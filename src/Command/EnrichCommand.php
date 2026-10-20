<?php

namespace App\Command;

use App\Entity\MetadataSuggestion;
use App\Entity\Music;
use App\Repository\MetadataSuggestionRepository;
use App\Repository\MusicRepository;
use App\Service\Enrichment\EnrichmentTrack;
use App\Service\Enrichment\HintExtractor;
use App\Service\Enrichment\MetadataEnricher;
use App\Service\Enrichment\Proposal;
use App\Service\Enrichment\SuggestionApplier;
use App\Service\MusicScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Completes missing/placeholder metadata ("Track 01", "Unknown Artist", no
 * track number, no cover) from MusicBrainz, AcoustID fingerprints and the
 * Cover Art Archive. Confident matches are written to the files and the
 * database; the others wait in /admin/suggestions.
 */
#[AsCommand(name: 'app:enrich', description: 'Complete track metadata (titles, album, track numbers, covers) from MusicBrainz/AcoustID')]
class EnrichCommand extends Command
{
    private const DEFAULT_THRESHOLD = 0.85;

    public function __construct(
        private readonly MusicRepository $musicRepository,
        private readonly MetadataSuggestionRepository $suggestions,
        private readonly MetadataEnricher $enricher,
        private readonly SuggestionApplier $applier,
        private readonly HintExtractor $hintExtractor,
        private readonly MusicScanner $scanner,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, write nothing')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Minimum score (0-1) to apply automatically; below it a suggestion is queued for review', (string) self::DEFAULT_THRESHOLD)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many tracks')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Only tracks whose path contains this text (e.g. an artist folder)')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Also retry incomplete tracks already attempted')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Process every track, even complete ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Metadata enrichment');

        $dryRun = (bool) $input->getOption('dry-run');
        $threshold = (float) $input->getOption('threshold');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $path = $input->getOption('path') !== null ? mb_strtolower((string) HintExtractor::nfc((string) $input->getOption('path'))) : null;
        $retry = (bool) $input->getOption('retry');
        $all = (bool) $input->getOption('all');

        if ($this->enricher->canFingerprint()) {
            $io->text('Audio fingerprinting: <info>on</info> (AcoustID + fpcalc)');
        } else {
            $io->warning('Audio fingerprinting off: set ACOUSTID_API_KEY in .env.local and install fpcalc (brew install chromaprint). Only name-based matching will run.');
        }
        if ($dryRun) {
            $io->note('Dry run: nothing is written.');
        }

        [$folders, $targetCount] = $this->planFolders($path, $retry, $all, $limit);
        if ($folders === []) {
            $io->success('Nothing to enrich.');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('%d track(s) to enrich in %d folder(s). MusicBrainz allows 1 request/s: this takes a while.', $targetCount, \count($folders)));
        $io->newLine();

        $stats = ['applied' => 0, 'pending' => 0, 'unmatched' => 0, 'failed' => 0];
        $log = static fn(string $line) => $io->writeln($line);
        $musicDir = $this->scanner->getMusicDir();

        foreach ($folders as $dir => $tracks) {
            $root = rtrim($musicDir, '/') . '/';
            $io->writeln(\sprintf('<comment>%s</comment>', str_starts_with($dir . '/', $root) ? (substr($dir, \strlen($root)) ?: $dir) : $dir));
            $proposals = $this->enricher->enrichFolder($tracks, $log);

            $byId = [];
            foreach ($proposals as $proposal) {
                $byId[$proposal->musicId] = $proposal;
            }

            foreach ($tracks as $track) {
                if (!$track->target) {
                    continue;
                }
                $proposal = $byId[$track->musicId] ?? null;
                if ($proposal === null) {
                    ++$stats['unmatched'];
                    $io->writeln(\sprintf('  <fg=gray>· %s — no match</>', basename($track->file)));
                } else {
                    $stats[$this->handle($io, $track, $proposal, $threshold, $dryRun)]++;
                }
            }

            if (!$dryRun) {
                $this->markAttempted($tracks);
                $this->em->flush();
            }
            $this->em->clear();
        }

        $io->newLine();
        $io->success(\sprintf(
            '%s — %d applied, %d queued for review, %d unmatched, %d failed.',
            $dryRun ? 'Dry run complete' : 'Enrichment complete',
            $stats['applied'],
            $stats['pending'],
            $stats['unmatched'],
            $stats['failed'],
        ));
        if ($stats['pending'] > 0 && !$dryRun) {
            $io->text('Review the queued suggestions at /admin/suggestions.');
        }

        return Command::SUCCESS;
    }

    /**
     * Groups tracks by folder; a folder is kept when it holds at least one
     * track to enrich, and keeps its complete siblings to help the match.
     *
     * @return array{0: array<string, list<EnrichmentTrack>>, 1: int} folders, number of tracks to enrich
     */
    private function planFolders(?string $path, bool $retry, bool $all, ?int $limit): array
    {
        $musicDir = $this->scanner->getMusicDir();
        $byDir = [];
        foreach ($this->musicRepository->findEnrichmentRows() as $row) {
            $byDir[\dirname($row['file'])][] = $row;
        }

        $folders = [];
        $targetCount = 0;
        foreach ($byDir as $dir => $rows) {
            $tracks = [];
            $hasTarget = false;
            foreach ($rows as $row) {
                $target = ($limit === null || $targetCount < $limit)
                    && ($path === null || str_contains(mb_strtolower((string) HintExtractor::nfc($row['file'])), $path))
                    && ($all || (($retry || $row['enrichedAt'] === null) && $this->isIncomplete($row, $musicDir)));
                if ($target) {
                    ++$targetCount;
                    $hasTarget = true;
                }
                $tracks[] = new EnrichmentTrack(
                    musicId: (int) $row['id'],
                    file: $row['file'],
                    title: $row['title'],
                    artist: $row['artist'],
                    album: $row['album'],
                    genre: $row['genre'],
                    duration: (int) $row['duration'],
                    trackNumber: $row['trackNumber'],
                    hasImage: $row['hasImage'],
                    target: $target,
                );
            }
            if ($hasTarget) {
                $folders[$dir] = $tracks;
            }
        }

        return [$folders, $targetCount];
    }

    /**
     * @param array{file: string, title: ?string, artist: ?string, album: ?string, duration: ?int, trackNumber: ?int, hasImage: bool} $row
     */
    private function isIncomplete(array $row, string $musicDir): bool
    {
        $hints = $this->hintExtractor->extract($row['file'], $musicDir, $row['title'], $row['artist'], $row['album'], (int) $row['duration'], $row['trackNumber']);

        return $hints->tagTitle === null
            || $hints->tagArtist === null
            || $hints->tagAlbum === null
            || $row['trackNumber'] === null
            || !$row['hasImage'];
    }

    /**
     * @return 'applied'|'pending'|'failed'
     */
    private function handle(SymfonyStyle $io, EnrichmentTrack $track, Proposal $proposal, float $threshold, bool $dryRun): string
    {
        $auto = $proposal->score >= $threshold;
        $f = $proposal->fields;
        $summary = \sprintf(
            '%s → %s%s — %s%s',
            basename($track->file),
            isset($f['trackNumber']) ? \sprintf('%02d. ', $f['trackNumber']) : '',
            $f['title'] ?? '?',
            $f['artist'] ?? '?',
            isset($f['album']) ? ' · ' . $f['album'] . (isset($f['year']) ? ' (' . $f['year'] . ')' : '') : '',
        );
        $tag = \sprintf('%.2f %s%s', $proposal->score, $proposal->source, $proposal->cover !== null ? ' +cover' : '');

        if ($dryRun) {
            $io->writeln(\sprintf('  %s %s <fg=gray>[%s]</>', $auto ? '<info>✓</info>' : '<comment>?</comment>', $summary, $tag));

            return $auto ? 'applied' : 'pending';
        }

        $music = $this->em->find(Music::class, $track->musicId);
        if ($music === null) {
            return 'failed';
        }

        $this->suggestions->removePendingForMusic($music);
        $suggestion = new MetadataSuggestion($music, $proposal->source, $proposal->score, $proposal->fields);
        $suggestion->setCover($proposal->cover);
        $suggestion->setMusicBrainzIds($proposal->releaseId, $proposal->recordingId);
        $suggestion->setScoreBreakdown($proposal->breakdown);
        $this->em->persist($suggestion);

        if (!$auto) {
            $io->writeln(\sprintf('  <comment>?</comment> %s <fg=gray>[%s → review]</>', $summary, $tag));

            return 'pending';
        }

        if (!$this->applier->apply($suggestion)) {
            $io->writeln(\sprintf('  <error>!</error> %s <fg=gray>[%s]</> %s', $summary, $tag, $suggestion->getError()));

            return 'failed';
        }
        $io->writeln(\sprintf('  <info>✓</info> %s <fg=gray>[%s]</>', $summary, $tag));

        return 'applied';
    }

    /**
     * @param list<EnrichmentTrack> $tracks
     */
    private function markAttempted(array $tracks): void
    {
        $now = new \DateTimeImmutable();
        foreach ($tracks as $track) {
            if ($track->target) {
                $this->em->find(Music::class, $track->musicId)?->setEnrichedAt($now);
            }
        }
    }
}
