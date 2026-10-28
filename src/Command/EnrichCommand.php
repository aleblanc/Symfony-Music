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
 *
 * --missing-year only fills the year: first from the other tracks of the
 * same album (no network), then from MusicBrainz for the rest; nothing else
 * of the track is changed.
 */
#[AsCommand(name: 'app:tool:enrich', description: 'Complete track metadata (titles, album, track numbers, covers) from MusicBrainz/AcoustID')]
class EnrichCommand extends Command
{
    private const DEFAULT_THRESHOLD = 0.85;
    /** Source of a year copied from the other tracks of the album. */
    private const SOURCE_ALBUM_YEAR = 'album-year';

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
            ->addOption('all', null, InputOption::VALUE_NONE, 'Process every track, even complete ones')
            ->addOption('missing-year', null, InputOption::VALUE_NONE, 'Only fill the year of the tracks without one: from the other tracks of the album, else MusicBrainz; nothing else changed')
            ->addOption('album-only', null, InputOption::VALUE_NONE, 'With --missing-year: only copy the year from the other tracks of the album, no MusicBrainz lookup');
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
        $albumOnly = (bool) $input->getOption('album-only');
        $missingYear = $albumOnly || $input->getOption('missing-year');

        if ($this->enricher->canFingerprint()) {
            $io->text('Audio fingerprinting: <info>on</info> (AcoustID + fpcalc)');
        } else {
            $io->warning('Audio fingerprinting off: set ACOUSTID_API_KEY in .env.local and install fpcalc (brew install chromaprint). Only name-based matching will run.');
        }
        if ($dryRun) {
            $io->note('Dry run: nothing is written.');
        }

        $stats = ['applied' => 0, 'pending' => 0, 'unmatched' => 0, 'failed' => 0];
        $filled = [];
        if ($missingYear) {
            $filled = $this->yearsFromAlbums($io, $path, $dryRun, $stats);
            if ($albumOnly) {
                $io->success(\sprintf('%d track(s) %s the year of their album%s.', \count($filled), $dryRun ? 'would get' : 'got', $stats['failed'] > 0 ? \sprintf(', %d failed', $stats['failed']) : ''));

                return Command::SUCCESS;
            }
        }

        [$folders, $targetCount] = $this->planFolders($path, $retry, $all, $limit, $missingYear, $filled);
        if ($folders === []) {
            $io->success($filled !== [] ? \sprintf('%d year(s) %s from the album, nothing left for MusicBrainz.', \count($filled), $dryRun ? 'would be copied' : 'copied') : 'Nothing to enrich.');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('%d track(s) to enrich in %d folder(s). MusicBrainz allows 1 request/s: this takes a while.', $targetCount, \count($folders)));
        $io->newLine();

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
                    $stats[$this->handle($io, $track, $proposal, $threshold, $dryRun, $missingYear)]++;
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
    /**
     * @param array<int, true> $skip tracks already given a year from their album
     */
    private function planFolders(?string $path, bool $retry, bool $all, ?int $limit, bool $missingYear = false, array $skip = []): array
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
                    && ($missingYear
                        ? $row['year'] === null && !isset($skip[(int) $row['id']])
                        : ($all || (($retry || $row['enrichedAt'] === null) && $this->isIncomplete($row, $musicDir))));
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
    private function handle(SymfonyStyle $io, EnrichmentTrack $track, Proposal $proposal, float $threshold, bool $dryRun, bool $yearOnly = false): string
    {
        if ($yearOnly) {
            $year = (int) ($proposal->fields['year'] ?? 0);
            if ($year <= 0) {
                $io->writeln(\sprintf('  <fg=gray>· %s — matched, but MusicBrainz has no year</>', basename($track->file)));

                return 'unmatched';
            }
            // The year alone: the title, album, cover... of the track stay as they are.
            $proposal = new Proposal($proposal->musicId, ['year' => $year], $proposal->score, $proposal->source, $proposal->releaseId, $proposal->recordingId, $proposal->breakdown);
        }
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
     * Tracks without a year whose album has one on its other tracks: the
     * album's (most common) year, applied like a suggestion (file + database,
     * revertable from /admin/suggestions).
     *
     * @param array{applied: int, pending: int, unmatched: int, failed: int} $stats
     *
     * @return array<int, true> the tracks given a year
     */
    private function yearsFromAlbums(SymfonyStyle $io, ?string $path, bool $dryRun, array &$stats): array
    {
        $albums = [];
        foreach ($this->musicRepository->findEnrichmentRows() as $row) {
            if ($row['albumKey'] !== null && trim((string) $row['album']) !== '') {
                $albums[$row['albumKey']][] = $row;
            }
        }
        $filled = [];
        foreach ($albums as $rows) {
            $years = array_count_values(array_filter(array_column($rows, 'year')));
            $missing = array_filter($rows, static fn (array $r): bool => $r['year'] === null
                && ($path === null || str_contains(mb_strtolower((string) HintExtractor::nfc($r['file'])), $path)));
            if ($years === [] || $missing === []) {
                continue;
            }
            arsort($years);
            $year = (int) array_key_first($years);
            $io->writeln(\sprintf('<comment>%s</comment> — %s: year %d (%d of %d tracks have it) → %d track(s)', $rows[0]['artist'] ?? '?', $rows[0]['album'], $year, array_sum($years), \count($rows), \count($missing)));
            foreach ($missing as $row) {
                $filled[(int) $row['id']] = true;
                if ($dryRun) {
                    ++$stats['applied'];
                    continue;
                }
                $music = $this->em->find(Music::class, $row['id']);
                if ($music === null) {
                    continue;
                }
                $suggestion = new MetadataSuggestion($music, self::SOURCE_ALBUM_YEAR, 1.0, ['year' => $year]);
                $this->em->persist($suggestion);
                if ($this->applier->apply($suggestion)) {
                    ++$stats['applied'];
                } else {
                    ++$stats['failed'];
                    $io->writeln(\sprintf('  <error>!</error> %s: %s', basename($row['file']), $suggestion->getError()));
                }
            }
            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        if ($filled !== []) {
            $io->newLine();
        }

        return $filled;
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
