<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Identifies the tracks of one folder against MusicBrainz and proposes
 * canonical metadata for them.
 *
 * 1. Album pass (folders of 2+ files): candidate releases come from AcoustID
 *    fingerprints of a few files, release searches built from path/tag hints
 *    and recording searches on titles found in file names. Each candidate is
 *    scored against the whole folder ({@see MatchScorer}) and the best one
 *    aligns files to tracks.
 * 2. Orphan pass (single files, catch-all folders, files the album pass left
 *    unaligned): per-file fingerprint, else a title/artist recording search;
 *    the recording's preferred release (official studio album, oldest
 *    edition) supplies album, track number and cover.
 *
 * Pure orchestration: nothing is written here, proposals are returned.
 */
class MetadataEnricher
{
    /** Below this, the album pass is ignored and files fall back to the orphan pass. */
    private const ALBUM_FLOOR = 0.5;
    /** Files fingerprinted per folder in the album pass. */
    private const FINGERPRINT_SAMPLE = 3;
    /** Release lookups (1 req/s each) spent per folder. */
    private const MAX_RELEASE_LOOKUPS = 6;
    private const ACOUSTID_MIN_SCORE = 0.5;

    private const UNKNOWN_GENRES = ['', 'unknown', 'unknown genre', 'other', 'genre inconnu'];

    /** @var array<string, ?string> release id => cover bytes */
    private array $coverCache = [];

    public function __construct(
        private readonly HintExtractor $hintExtractor,
        private readonly MatchScorer $scorer,
        private readonly MusicBrainzClient $musicBrainz,
        private readonly AcoustIdClient $acoustId,
        private readonly Fingerprinter $fingerprinter,
        private readonly CoverArtClient $coverArt,
        private readonly string $musicDir,
    ) {}

    public function canFingerprint(): bool
    {
        return $this->acoustId->isConfigured() && $this->fingerprinter->isAvailable();
    }

    /**
     * @param list<EnrichmentTrack> $tracks every indexed track of one folder; only `target` ones get proposals
     * @param callable(string):void $log
     *
     * @return list<Proposal>
     */
    public function enrichFolder(array $tracks, callable $log): array
    {
        try {
            return $this->doEnrichFolder($tracks, $log);
        } catch (EnrichmentException|HttpException $e) {
            $log(\sprintf('  ! %s: %s', \dirname($tracks[0]->file ?? '?'), $e->getMessage()));

            return [];
        }
    }

    /**
     * @param list<EnrichmentTrack> $tracks
     * @param callable(string):void $log
     *
     * @return list<Proposal>
     */
    private function doEnrichFolder(array $tracks, callable $log): array
    {
        /** @var array<int, EnrichmentTrack> $byId */
        $byId = [];
        /** @var array<int, TrackHints> $hints */
        $hints = [];
        foreach ($tracks as $track) {
            $byId[$track->musicId] = $track;
            $hints[$track->musicId] = $this->hintExtractor->extract($track->file, $this->musicDir, $track->title, $track->artist, $track->album, $track->duration, $track->trackNumber);
        }
        $targets = array_keys(array_filter($byId, static fn(EnrichmentTrack $t): bool => $t->target));
        if ($targets === []) {
            return [];
        }

        /** @var array<int, list<RecordingMatch>> $acoustMatches music id => AcoustID matches */
        $acoustMatches = [];
        $proposals = [];

        if (\count($tracks) >= 2) {
            $sample = $this->fingerprintSample($targets, $hints);
            foreach ($sample as $id) {
                $acoustMatches[$id] = $this->lookupFingerprint($byId[$id]);
            }

            $match = $this->bestAlbumMatch($tracks, $hints, $acoustMatches, $log);
            if ($match !== null) {
                $log(\sprintf('  = %s — %s (score %.2f, %d/%d aligned)', $match->release->artist, $match->release->title, $match->score, \count($match->assignments), \count($tracks)));
                foreach ($targets as $id) {
                    $releaseTrack = $match->assignments[$id] ?? null;
                    if ($releaseTrack !== null) {
                        $proposals[$id] = $this->proposal($byId[$id], $match->release, $releaseTrack, $match->score, Proposal::SOURCE_ALBUM, $match->breakdown);
                    }
                }
            }
        }

        foreach ($targets as $id) {
            if (isset($proposals[$id])) {
                continue;
            }
            $orphan = $this->identifyOrphan($byId[$id], $hints[$id], $acoustMatches[$id] ?? null);
            if ($orphan !== null) {
                $proposals[$id] = $orphan;
            }
        }

        return array_values($proposals);
    }

    /**
     * Placeholder-titled files first: they are the ones names cannot help with.
     *
     * @param list<int> $targets
     * @param array<int, TrackHints> $hints
     *
     * @return list<int>
     */
    private function fingerprintSample(array $targets, array $hints): array
    {
        if (!$this->canFingerprint()) {
            return [];
        }
        usort($targets, static fn(int $a, int $b): int => (int) $hints[$b]->titleIsPlaceholder <=> (int) $hints[$a]->titleIsPlaceholder);

        return \array_slice($targets, 0, self::FINGERPRINT_SAMPLE);
    }

    /**
     * @return list<RecordingMatch>
     */
    private function lookupFingerprint(EnrichmentTrack $track): array
    {
        if (!$this->canFingerprint()) {
            return [];
        }
        try {
            $matches = $this->acoustId->lookup($this->fingerprinter->fingerprint($track->file));
        } catch (EnrichmentException) {
            return [];
        }

        return array_values(array_filter($matches, static fn(RecordingMatch $m): bool => $m->score >= self::ACOUSTID_MIN_SCORE));
    }

    /**
     * @param list<EnrichmentTrack> $tracks
     * @param array<int, TrackHints> $hints
     * @param array<int, list<RecordingMatch>> $acoustMatches
     * @param callable(string):void $log
     */
    private function bestAlbumMatch(array $tracks, array $hints, array $acoustMatches, callable $log): ?AlbumMatch
    {
        $candidates = $this->candidateReleaseIds($tracks, $hints, $acoustMatches);
        if ($candidates === []) {
            return null;
        }

        $folder = [];
        foreach ($tracks as $track) {
            $folder[] = new FolderTrack(
                $track->musicId,
                $hints[$track->musicId],
                array_map(static fn(RecordingMatch $m): string => $m->id, \array_slice($acoustMatches[$track->musicId] ?? [], 0, 3)),
            );
        }
        $fingerprinted = array_filter($acoustMatches, static fn(array $m): bool => $m !== []);

        $best = null;
        foreach (\array_slice($candidates, 0, self::MAX_RELEASE_LOOKUPS) as $releaseId) {
            $release = $this->musicBrainz->getRelease($releaseId);
            if ($release === null || $release->tracks === []) {
                continue;
            }
            $match = $this->scorer->score($folder, $release, $this->consensus($fingerprinted, $release));
            if ($best === null || $match->score > $best->score
                || ($match->score === $best->score && $best->release->isCompilation && !$release->isCompilation)) {
                $best = $match;
            }
        }

        if ($best === null || $best->score < self::ALBUM_FLOOR || $best->assignments === []) {
            if ($best !== null) {
                $log(\sprintf('  ? best album guess "%s" too weak (%.2f), trying files one by one', $best->release->title, $best->score));
            }

            return null;
        }

        return $best;
    }

    /**
     * Share of fingerprinted files whose AcoustID recordings appear on this
     * release (recording ids are shared across editions, release ids are not).
     *
     * @param array<int, list<RecordingMatch>> $fingerprinted
     */
    private function consensus(array $fingerprinted, ReleaseCandidate $release): ?float
    {
        if ($fingerprinted === []) {
            return null;
        }
        $recordings = array_flip(array_filter(array_map(static fn(ReleaseTrack $t): ?string => $t->recordingId, $release->tracks)));
        $hits = 0;
        foreach ($fingerprinted as $matches) {
            foreach (\array_slice($matches, 0, 3) as $match) {
                if (isset($recordings[$match->id])) {
                    ++$hits;
                    break;
                }
            }
        }

        return $hits / \count($fingerprinted);
    }

    /**
     * Release ids worth a full lookup, most promising first.
     *
     * @param list<EnrichmentTrack> $tracks
     * @param array<int, TrackHints> $hints
     * @param array<int, list<RecordingMatch>> $acoustMatches
     *
     * @return list<string>
     */
    private function candidateReleaseIds(array $tracks, array $hints, array $acoustMatches): array
    {
        $size = \count($tracks);
        /** @var array<string, float> $weights */
        $weights = [];
        $add = static function (string $id, float $weight) use (&$weights): void {
            if ($id !== '') {
                $weights[$id] = ($weights[$id] ?? 0.0) + $weight;
            }
        };

        foreach ($acoustMatches as $matches) {
            foreach (\array_slice($matches, 0, 3) as $match) {
                foreach ($this->rankReleases($match->releases, $size, null) as $release) {
                    $add($release->id, 3.0 * $match->score);
                }
            }
        }

        $pairs = [];
        foreach ($hints as $h) {
            foreach ($h->albumSearchPairs() as $pair) {
                $pairs[mb_strtolower(($pair[0] ?? '') . "\0" . $pair[1])] ??= $pair;
            }
        }
        foreach (\array_slice(array_values($pairs), 0, 3) as [$artist, $album]) {
            foreach (\array_slice($this->rankReleases($this->musicBrainz->searchReleases($artist, $album), $size, $album), 0, 2) as $release) {
                $add($release->id, 2.0 * $release->score);
            }
        }

        // Titles from file names ("01 Papercut" in "Linkin Park/Album inconnu").
        $titled = array_filter($hints, static fn(TrackHints $h): bool => $h->bestTitle() !== null && $h->artistGuess() !== null);
        foreach (\array_slice($titled, 0, 3) as $h) {
            foreach (\array_slice($this->musicBrainz->searchRecordings($h->artistGuess(), (string) $h->bestTitle()), 0, 2) as $recording) {
                foreach (\array_slice($this->rankReleases($recording->releases, $size, null), 0, 3) as $release) {
                    $add($release->id, $recording->score);
                }
            }
        }

        arsort($weights);

        return array_keys($weights);
    }

    /**
     * Orders releases by preference: official studio album first, close to
     * the folder's file count, similar title, oldest edition.
     *
     * @param list<ReleaseSummary> $releases
     *
     * @return list<ReleaseSummary>
     */
    private function rankReleases(array $releases, int $fileCount, ?string $albumHint): array
    {
        $rank = function (ReleaseSummary $r) use ($fileCount, $albumHint): float {
            $value = (float) $r->preferenceRank();
            if ($r->trackCount > 0) {
                $value -= min(4, abs($r->trackCount - $fileCount) / 3);
            }
            if ($albumHint !== null && $this->scorer->similarity($r->title, $albumHint) >= 0.85) {
                $value += 3;
            }

            return $value;
        };
        usort($releases, static fn(ReleaseSummary $a, ReleaseSummary $b): int => [$rank($b), $a->date ?? '9999'] <=> [$rank($a), $b->date ?? '9999']);

        return $releases;
    }

    /**
     * @param ?list<RecordingMatch> $acoustMatches already looked up in the album pass, null if not
     */
    private function identifyOrphan(EnrichmentTrack $track, TrackHints $hints, ?array $acoustMatches): ?Proposal
    {
        $acoustMatches ??= $this->lookupFingerprint($track);
        $best = $acoustMatches[0] ?? null;
        if ($best !== null) {
            $recording = $this->musicBrainz->getRecording($best->id) ?? $best;
            $score = $best->score * $this->durationFactor($track->duration, $recording->duration);

            return $this->proposalForRecording($track, $hints, $recording, $score, Proposal::SOURCE_ACOUSTID, ['acoustid' => $best->score]);
        }

        $title = $hints->bestTitle();
        $artist = $hints->artistGuess();
        if ($title === null || $artist === null) {
            return null;
        }

        $bestScore = 0.0;
        $bestRecording = null;
        $breakdown = [];
        foreach ($this->musicBrainz->searchRecordings($artist, $title) as $recording) {
            $titleSim = $this->scorer->similarity($title, $recording->title);
            $artistSim = $this->scorer->similarity($artist, $recording->artist);
            $duration = $this->durationFactor($track->duration, $recording->duration);
            $score = round(0.45 * $titleSim + 0.35 * $artistSim + 0.20 * $duration, 3);
            if ($score > $bestScore) {
                [$bestScore, $bestRecording] = [$score, $recording];
                $breakdown = ['title' => round($titleSim, 3), 'artist' => round($artistSim, 3), 'duration' => round($duration, 3)];
            }
        }

        return $bestRecording !== null && $bestScore >= self::ALBUM_FLOOR
            ? $this->proposalForRecording($track, $hints, $bestRecording, $bestScore, Proposal::SOURCE_SEARCH, $breakdown)
            : null;
    }

    /**
     * @param array<string, float> $breakdown
     */
    private function proposalForRecording(EnrichmentTrack $track, TrackHints $hints, RecordingMatch $recording, float $score, string $source, array $breakdown): ?Proposal
    {
        $albumHint = $hints->tagAlbum ?? ($hints->pathSegments[0] ?? null);
        foreach (\array_slice($this->rankReleases($recording->releases, 1, $albumHint), 0, 2) as $summary) {
            $release = $this->musicBrainz->getRelease($summary->id);
            foreach ($release?->tracks ?? [] as $releaseTrack) {
                if ($releaseTrack->recordingId === $recording->id) {
                    return $this->proposal($track, $release, $releaseTrack, $score, $source, $breakdown);
                }
            }
        }

        // Recording known but no usable release: title and artist only.
        return new Proposal($track->musicId, ['title' => $recording->title, 'artist' => $recording->artist], round($score, 3), $source, null, $recording->id, $breakdown);
    }

    /**
     * @param array<string, float> $breakdown
     */
    private function proposal(EnrichmentTrack $track, ReleaseCandidate $release, ReleaseTrack $releaseTrack, float $score, string $source, array $breakdown): Proposal
    {
        $fields = [
            'title' => $releaseTrack->title,
            'artist' => $releaseTrack->artist,
            'album' => $release->title,
            'albumArtist' => $release->artist,
            'trackNumber' => $releaseTrack->position,
            'trackTotal' => $release->discTrackCount($releaseTrack->disc),
            'discNumber' => $releaseTrack->disc,
            'discTotal' => $release->discCount(),
            'year' => $release->year,
        ];
        if ($release->genre !== null && \in_array(mb_strtolower(trim((string) $track->genre)), self::UNKNOWN_GENRES, true)) {
            $fields['genre'] = $release->genre;
        }

        $cover = null;
        if (!$track->hasImage) {
            $cover = $this->coverCache[$release->id] ??= $this->coverArt->front($release->id, $release->releaseGroupId);
        }

        return new Proposal($track->musicId, $fields, round($score, 3), $source, $release->id, $releaseTrack->recordingId, $breakdown, $cover);
    }

    private function durationFactor(int $local, ?int $remote): float
    {
        if ($local <= 0 || $remote === null) {
            return 0.7;
        }

        return max(0.0, min(1.0, 1 - (abs($local - $remote) - 3) / 10));
    }
}
