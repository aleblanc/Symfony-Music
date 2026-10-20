<?php

namespace App\Command;

use App\Entity\ImportItem;
use App\Repository\ImportItemRepository;
use App\Service\Enrichment\EnrichmentTrack;
use App\Service\Enrichment\MetadataEnricher;
use App\Service\Enrichment\Proposal;
use App\Service\Import\AudioFileReader;
use App\Service\Import\FileImporter;
use App\Service\Import\ImportResult;
use App\Service\Import\LibraryPath;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 1 of adding music: identifies the files of the import folder
 * (AcoustID fingerprints + MusicBrainz), writes their tags, cover and
 * MusicBrainz ids, and files them into MUSIC_DIR as
 * Artist/Album (Year)/NN Title. Files not identified surely enough stay in
 * the import folder, their candidates stored for a choice in the web page.
 *
 * Then run `app:scan` and `app:spotify:match` yourself.
 */
#[AsCommand(name: 'app:import', description: 'Identify, tag and file the import folder into the library (MUSIC_DIR/Artist/Album/NN Title)')]
class ImportCommand extends Command
{
    private const DEFAULT_THRESHOLD = 0.85;

    public function __construct(
        private readonly AudioFileReader $reader,
        private readonly MetadataEnricher $enricher,
        private readonly FileImporter $filer,
        private readonly LibraryPath $libraryPath,
        private readonly ImportItemRepository $items,
        private readonly EntityManagerInterface $em,
        private readonly string $importDir,
        private readonly string $musicDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Folder to import (default: IMPORT_DIR)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be tagged and where it would be filed; change nothing')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Minimum score (0-1) to tag and file automatically; below it the candidates wait in the web page', (string) self::DEFAULT_THRESHOLD)
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Also retry files already tried (unchanged since)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Import');

        $source = rtrim((string) ($input->getOption('source') ?? $this->importDir), '/');
        $library = rtrim($this->musicDir, '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $retry = (bool) $input->getOption('retry');
        $threshold = (float) $input->getOption('threshold');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        if (!is_dir($source)) {
            $io->error(\sprintf('Import folder "%s" does not exist (set IMPORT_DIR in .env.local).', $source));

            return Command::FAILURE;
        }
        $realSource = (string) realpath($source);
        $realLibrary = is_dir($library) ? (string) realpath($library) : $library;
        if ($realSource === $realLibrary || str_starts_with($realSource . '/', $realLibrary . '/') || str_starts_with($realLibrary . '/', $realSource . '/')) {
            $io->error('The import folder and MUSIC_DIR must be separate folders (neither inside the other). To complete tracks already in the library, use app:enrich.');

            return Command::FAILURE;
        }

        $io->definitionList(['From' => $source], ['Into' => $library . ($dryRun ? '  (dry run: nothing written or moved)' : '')]);
        if ($this->enricher->canFingerprint()) {
            $io->text('Audio fingerprinting: <info>on</info> (AcoustID + fpcalc)');
        } else {
            $io->warning('Audio fingerprinting off: set ACOUSTID_API_KEY in .env.local and install fpcalc (brew install chromaprint). Only name-based matching will run.');
        }

        $stats = ['imported' => 0, 'duplicates' => 0, 'pending' => 0, 'unmatched' => 0, 'failed' => 0, 'skipped' => 0];
        $log = static fn(string $line) => $io->writeln($line);
        $processed = 0;

        foreach ($this->reader->listFiles($source) as $dir => $files) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            // Every file of the folder helps the album match; only those not
            // already tried (or changed since) are targets.
            $tracks = [];
            $byId = [];
            foreach ($files as $i => $file) {
                $size = (int) filesize($file);
                $mtime = (int) filemtime($file);
                $item = $this->items->findOneByPath($file);
                $target = ($retry || $item === null || !$item->isUnchanged($size, $mtime))
                    && ($limit === null || $processed < $limit);
                if ($target) {
                    ++$processed;
                } else {
                    ++$stats['skipped'];
                }
                $tags = $this->reader->read($file);
                $tracks[] = new EnrichmentTrack($i, $file, $tags['title'], $tags['artist'], $tags['album'], $tags['genre'], $tags['duration'], $tags['trackNumber'], $tags['hasImage'], $target);
                $byId[$i] = [$file, $size, $mtime, $item];
            }
            if (array_filter($tracks, static fn(EnrichmentTrack $t): bool => $t->target) === []) {
                continue;
            }

            $io->writeln(\sprintf('<comment>%s</comment>', substr($dir, \strlen($source) + 1) ?: '.'));
            $candidates = $this->enricher->identifyFolder($tracks, $log, $source);

            foreach ($tracks as $track) {
                if (!$track->target) {
                    continue;
                }
                [$file, $size, $mtime, $item] = $byId[$track->musicId];
                $list = $candidates[$track->musicId] ?? [];
                $outcome = $this->handle($io, $file, $source, $list, $threshold, $dryRun, $size, $mtime, $item);
                ++$stats[$outcome];
            }

            if (!$dryRun) {
                $this->em->flush();
            }
            $this->em->clear();
        }

        $io->newLine();
        $io->success(\sprintf(
            '%s — %d filed, %d duplicates, %d waiting for a choice, %d not identified, %d failed, %d already tried.',
            $dryRun ? 'Dry run complete' : 'Import complete',
            $stats['imported'],
            $stats['duplicates'],
            $stats['pending'],
            $stats['unmatched'],
            $stats['failed'],
            $stats['skipped'],
        ));
        if (!$dryRun && $stats['imported'] > 0) {
            $io->text('Next: php bin/console app:scan, then php bin/console app:spotify:match');
        }
        if ($stats['skipped'] > 0) {
            $io->text('Files already tried are skipped until they change; --retry forces them.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<Proposal> $list candidates, best first
     *
     * @return 'imported'|'duplicates'|'pending'|'unmatched'|'failed'
     */
    private function handle(SymfonyStyle $io, string $file, string $source, array $list, float $threshold, bool $dryRun, int $size, int $mtime, ?ImportItem $item): string
    {
        $name = basename($file);
        $best = $list[0] ?? null;

        if ($best === null) {
            $io->writeln(\sprintf('  <fg=gray>· %s — not identified</>', $name));
            if (!$dryRun) {
                $this->item($file, $size, $mtime, $item)->recordAttempt($size, $mtime, []);
            }

            return 'unmatched';
        }

        $ext = pathinfo($file, \PATHINFO_EXTENSION);
        $tag = \sprintf('%.2f %s%s', $best->score, $best->source, $best->cover !== null ? ' +cover' : '');
        if ($best->score < $threshold) {
            $io->writeln(\sprintf('  <comment>?</comment> %s → %s <fg=gray>[%s, %d candidate(s) → web page]</>', $name, $this->libraryPath->relativePath($best->fields, $ext), $tag, \count($list)));
            if (!$dryRun) {
                $this->item($file, $size, $mtime, $item)->recordAttempt($size, $mtime, $list);
            }

            return 'pending';
        }

        if ($dryRun) {
            $io->writeln(\sprintf('  <info>✓</info> %s → %s <fg=gray>[%s]</>', $name, $this->libraryPath->relativePath($best->fields, $ext), $tag));

            return 'imported';
        }

        $record = $this->item($file, $size, $mtime, $item);
        try {
            $result = $this->filer->import($file, $source, $best, $best->cover);
        } catch (\RuntimeException $e) {
            $record->recordAttempt($size, $mtime, $list, $e->getMessage());
            $io->writeln(\sprintf('  <error>!</error> %s <fg=gray>[%s]</> %s', $name, $tag, $e->getMessage()));

            return 'failed';
        }

        $shown = substr($result->destination, \strlen(rtrim($result->status === ImportResult::DUPLICATE ? $source : $this->musicDir, '/')) + 1);
        if ($result->status === ImportResult::DUPLICATE) {
            $record->markDuplicate($result->destination);
            $io->writeln(\sprintf('  <fg=yellow>=</> %s — already in the library → %s', $name, $shown));

            return 'duplicates';
        }
        $record->markImported($result->destination);
        $io->writeln(\sprintf('  <info>✓</info> %s → %s <fg=gray>[%s]</>', $name, $shown, $tag));

        return 'imported';
    }

    private function item(string $file, int $size, int $mtime, ?ImportItem $item): ImportItem
    {
        if ($item !== null) {
            return $this->em->contains($item) ? $item : ($this->items->find($item->getId()) ?? $item);
        }
        $item = $this->items->findOneByPath($file) ?? new ImportItem($file, $size, $mtime);
        $this->em->persist($item);

        return $item;
    }
}
