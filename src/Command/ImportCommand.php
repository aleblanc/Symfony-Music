<?php

namespace App\Command;

use App\Entity\ImportItem;
use App\Repository\ImportItemRepository;
use App\Service\Enrichment\EnrichmentTrack;
use App\Service\Enrichment\MetadataEnricher;
use App\Service\Enrichment\Proposal;
use App\Service\Import\AudioFileReader;
use App\Service\Import\FileImporter;
use App\Service\Import\FlacConverter;
use App\Service\Import\ImportResult;
use App\Service\Import\LibraryPath;
use App\Service\MetadataWriter;
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
 * the import folder, their candidates stored for a choice in the web page;
 * files nothing identifies at all are kept anyway, as they are, under
 * MUSIC_DIR/_unclassified (--no-unclassified leaves them in the import folder).
 *
 * Then run `app:scan` and `app:spotify:match` yourself.
 */
#[AsCommand(name: 'app:import', description: 'Identify, tag and file the import folder into the library (MUSIC_DIR/Artist/Album/NN Title)')]
class ImportCommand extends Command
{
    private const DEFAULT_THRESHOLD = 0.85;
    /** Statuses --only can select (imported and duplicate files left the import folder). */
    private const RETRYABLE_STATUSES = [ImportItem::FAILED, ImportItem::SKIPPED, ImportItem::PENDING, ImportItem::UNMATCHED];

    public function __construct(
        private readonly AudioFileReader $reader,
        private readonly MetadataEnricher $enricher,
        private readonly FileImporter $filer,
        private readonly FlacConverter $flacConverter,
        private readonly LibraryPath $libraryPath,
        private readonly MetadataWriter $writer,
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
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only files already tried with these statuses, comma-separated: failed, skipped, pending, unmatched (e.g. --only=failed,skipped)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many files')
            ->addOption('flac-to-aac', null, InputOption::VALUE_NONE, 'Convert the FLAC files to M4A (AAC 256 kb/s, about 4 times smaller) before filing them; originals moved to IMPORT_DIR/' . FlacConverter::ORIGINALS_DIR)
            ->addOption('no-unclassified', null, InputOption::VALUE_NONE, 'Leave the files nothing identifies in the import folder instead of filing them as they are under MUSIC_DIR/' . FileImporter::UNCLASSIFIED_DIR);
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
        $unclassified = !$input->getOption('no-unclassified');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $only = null;
        if ($input->getOption('only') !== null) {
            $only = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $input->getOption('only'))))));
            $unknown = array_diff($only, self::RETRYABLE_STATUSES);
            if ($only === [] || $unknown !== []) {
                $io->error(\sprintf('--only accepts: %s.', implode(', ', self::RETRYABLE_STATUSES)));

                return Command::FAILURE;
            }
        }

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
        if ($input->getOption('flac-to-aac') && !$this->convertFlac($io, $source, $dryRun)) {
            return Command::FAILURE;
        }
        if ($this->enricher->canFingerprint()) {
            $io->text('Audio fingerprinting: <info>on</info> (AcoustID + fpcalc)');
        } else {
            $io->warning('Audio fingerprinting off: set ACOUSTID_API_KEY in .env.local and install fpcalc (brew install chromaprint). Only name-based matching will run.');
        }

        $stats = ['imported' => 0, 'duplicates' => 0, 'pending' => 0, 'unclassified' => 0, 'unmatched' => 0, 'failed' => 0, 'skipped' => 0];
        $log = static fn(string $line) => $io->writeln($line);
        $processed = 0;

        foreach ($this->reader->listFiles($source) as $dir => $files) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            // Broken symlinks, files not downloaded from iCloud…: reported and
            // skipped, never fatal for the whole run.
            $readable = [];
            foreach ($files as $file) {
                if (is_file($file) && is_readable($file) && @filesize($file) !== false) {
                    $readable[] = $file;
                } else {
                    $io->writeln(\sprintf('  <error>!</error> %s — unreadable (broken link, or not downloaded from iCloud?), skipped', substr($file, \strlen($source) + 1)));
                    ++$stats['failed'];
                }
            }
            $files = $readable;
            if ($files === []) {
                continue;
            }

            // Every file of the folder helps the album match; only those not
            // already tried (or changed since) — or, with --only, those with
            // the requested statuses — are targets.
            $targets = [];
            $byId = [];
            foreach ($files as $i => $file) {
                $size = (int) filesize($file);
                $mtime = (int) filemtime($file);
                $item = $this->items->findOneByPath($file);
                $wanted = $only !== null
                    ? $item !== null && \in_array($item->getStatus(), $only, true)
                    : $retry || $item === null || !$item->isUnchanged($size, $mtime);
                $targets[$i] = $wanted && ($limit === null || $processed < $limit);
                if ($targets[$i]) {
                    ++$processed;
                } elseif ($only === null) {
                    ++$stats['skipped'];
                }
                $byId[$i] = [$file, $size, $mtime, $item];
            }
            // Tags are read only for folders with something to do.
            if (!\in_array(true, $targets, true)) {
                continue;
            }
            $tracks = [];
            foreach ($files as $i => $file) {
                $tags = $this->reader->read($file);
                $tracks[] = new EnrichmentTrack($i, $file, $tags['title'], $tags['artist'], $tags['album'], $tags['genre'], $tags['duration'], $tags['trackNumber'], $tags['hasImage'], $targets[$i]);
            }

            $io->writeln(\sprintf('<comment>%s</comment>', substr($dir, \strlen($source) + 1) ?: '.'));
            $candidates = $this->enricher->identifyFolder($tracks, $log, $source);

            foreach ($tracks as $track) {
                if (!$track->target) {
                    continue;
                }
                [$file, $size, $mtime, $item] = $byId[$track->musicId];
                $list = $candidates[$track->musicId] ?? [];
                $outcome = $this->handle($io, $file, $source, $list, $threshold, $dryRun, $unclassified, $size, $mtime, $item);
                ++$stats[$outcome];
            }

            if (!$dryRun) {
                $this->em->flush();
            }
            $this->em->clear();
        }

        $io->newLine();
        $io->success(\sprintf(
            '%s — %d filed, %d duplicates, %d waiting for a choice, %d unidentified filed in %s, %d not identified (left), %d failed, %d already tried.',
            $dryRun ? 'Dry run complete' : 'Import complete',
            $stats['imported'],
            $stats['duplicates'],
            $stats['pending'],
            $stats['unclassified'],
            FileImporter::UNCLASSIFIED_DIR,
            $stats['unmatched'],
            $stats['failed'],
            $stats['skipped'],
        ));
        if (!$dryRun && $stats['imported'] + $stats['unclassified'] > 0) {
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
     * @return 'imported'|'duplicates'|'pending'|'unclassified'|'unmatched'|'failed'
     */
    private function handle(SymfonyStyle $io, string $file, string $source, array $list, float $threshold, bool $dryRun, bool $unclassified, int $size, int $mtime, ?ImportItem $item): string
    {
        $name = basename($file);
        $best = $list[0] ?? null;

        if ($best === null && $unclassified) {
            return $this->fileUnclassified($io, $file, $source, $dryRun, $size, $mtime, $item);
        }
        if ($best === null) {
            $io->writeln(\sprintf('  <fg=gray>· %s — not identified</>', $name));
            if (!$dryRun) {
                $this->item($file, $size, $mtime, $item)->recordAttempt($size, $mtime, []);
            }

            return 'unmatched';
        }

        // Same extension FileImporter will use: the real format's.
        $ext = $this->writer->detectFormat($file) ?? pathinfo($file, \PATHINFO_EXTENSION);
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

    /**
     * Not identified: kept anyway, filed as is under MUSIC_DIR/_unclassified.
     *
     * @return 'unclassified'|'duplicates'|'failed'
     */
    private function fileUnclassified(SymfonyStyle $io, string $file, string $source, bool $dryRun, int $size, int $mtime, ?ImportItem $item): string
    {
        $name = basename($file);
        $relative = FileImporter::UNCLASSIFIED_DIR . '/' . substr($file, \strlen($source) + 1);
        if ($dryRun) {
            $io->writeln(\sprintf('  <fg=cyan>·</> %s — not identified → %s', $name, $relative));

            return 'unclassified';
        }

        $record = $this->item($file, $size, $mtime, $item);
        try {
            $result = $this->filer->fileUnclassified($file, $source);
        } catch (\RuntimeException $e) {
            $record->recordAttempt($size, $mtime, [], $e->getMessage());
            $io->writeln(\sprintf('  <error>!</error> %s — not identified, and not filed: %s', $name, $e->getMessage()));

            return 'failed';
        }
        if ($result->status === ImportResult::DUPLICATE) {
            $record->markDuplicate($result->destination);
            $io->writeln(\sprintf('  <fg=yellow>=</> %s — not identified, already in %s → %s', $name, FileImporter::UNCLASSIFIED_DIR, substr($result->destination, \strlen($source) + 1)));

            return 'duplicates';
        }
        $record->markImported($result->destination);
        $io->writeln(\sprintf('  <fg=cyan>·</> %s — not identified → %s', $name, substr($result->destination, \strlen(rtrim($this->musicDir, '/')) + 1)));

        return 'unclassified';
    }

    /**
     * --flac-to-aac: every FLAC of the import folder becomes M4A (AAC 256)
     * first, so it is identified and filed like any other file. False when
     * the conversion cannot run at all.
     */
    private function convertFlac(SymfonyStyle $io, string $source, bool $dryRun): bool
    {
        $files = $this->flacConverter->findFlac($source);
        if ($files === []) {
            return true;
        }
        if ($this->flacConverter->binaryPath() === null) {
            $io->error('--flac-to-aac needs ffmpeg: brew install ffmpeg (or set FFMPEG_PATH).');

            return false;
        }
        if (!$this->writer->canWrite('m4a')) {
            $io->warning('AtomicParsley not found (brew install atomicparsley): the converted M4A files would be filed without their identified tags.');
        }
        if ($dryRun) {
            $io->text(\sprintf('%d FLAC file(s) would be converted to M4A (AAC 256 kb/s) first; this dry run shows them as FLAC.', \count($files)));

            return true;
        }

        $io->text(\sprintf('Converting %d FLAC file(s) to M4A (AAC 256 kb/s, %s encoder), originals to IMPORT_DIR/%s…', \count($files), $this->flacConverter->encoder(), FlacConverter::ORIGINALS_DIR));
        foreach ($files as $file) {
            $name = substr($file, \strlen($source) + 1);
            try {
                $m4a = $this->flacConverter->convert($file, $source);
                $io->writeln(\sprintf('  <info>♪</info> %s → %s <fg=gray>[%.1f → %.1f MB]</>', $name, basename($m4a), filesize($source . '/' . FlacConverter::ORIGINALS_DIR . '/' . $name) / 1048576, filesize($m4a) / 1048576));
            } catch (\RuntimeException $e) {
                $io->writeln(\sprintf('  <error>!</error> %s — %s (left as FLAC)', $name, $e->getMessage()));
            }
        }
        $io->newLine();

        return true;
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
