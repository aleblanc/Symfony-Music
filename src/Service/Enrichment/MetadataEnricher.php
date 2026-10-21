<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
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
    /** Confidence given when every name of the file agrees with the album track. */
    private const NAMES_AGREE_SCORE = 0.9;
    /** Candidates kept per doubtful file for the web page. */
    private const MAX_CANDIDATES = 5;
    /** Below this, the album pass is ignored and files fall back to the orphan pass. */
    private const ALBUM_FLOOR = 0.5;
    /** AcoustID recordings looked up on MusicBrainz to choose among (album take vs remix/demo). */
    private const FINGERPRINT_CHOICES = 6;
    /** Fingerprint scores this close are a tie, settled by the release (AcoustID scores are noisy). */
    private const FINGERPRINT_TIE = 0.05;
    /** Upper bound of files fingerprinted per folder (catch-all folders). */
    private const MAX_FINGERPRINTS = 60;
    /** AcoustID recordings kept per file (remasters/editions share a fingerprint). */
    private const ACOUSTID_RECORDINGS = 10;
    /** An album is only auto-applied when at least this share of fingerprints agrees (beets: 60 %). */
    private const FINGERPRINT_MAJORITY = 0.5;
    /** A different album scoring within this gap of the best one blocks auto-apply (beets' rec_gap_thresh). */
    private const RIVAL_GAP = 0.05;
    /** Release lookups (1 req/s each) spent per folder. */
    private const MAX_RELEASE_LOOKUPS = 8;
    /** Releases of a recording tried (best first) to find its track number, album and cover. */
    private const RELEASES_PER_RECORDING = 4;
    /** Release search depth: equal-score results come back in no stable order. */
    private const RELEASE_SEARCH_LIMIT = 25;
    private const ACOUSTID_MIN_SCORE = 0.5;
    /**
     * Recording search depth (MusicBrainz maximum): popular songs have dozens
     * of equal-score recordings returned in no stable order — with 25 the
     * album recording of "No Surprises" (one of 94) was sometimes missing.
     */
    private const RECORDING_SEARCH_LIMIT = 100;
    /**
     * Title-only fuzzy search (last resort when the artist name itself is
     * spelled differently: "Sum41" for "Sum 41"): recordings by an artist
     * less similar than this are someone else's song of the same title.
     */
    private const TITLE_ONLY_MIN_ARTIST = 0.75;

    private const UNKNOWN_GENRES = ['', 'unknown', 'unknown genre', 'other', 'genre inconnu'];

    /** Album pass scores below this also try per-file identification and keep the better one. */
    private const CONFIDENT = 0.85;

    /** @var array<string, ?string> release id => cover bytes */
    private array $coverCache = [];

    /** @var list<AlbumMatch> runner-up albums of the current folder */
    private array $albumAlternatives = [];

    /** Whether the current folder is a live recording (live releases allowed). */
    private bool $allowLive = false;

    /**
     * Albums already chosen for (or guessed from) the current folder: files
     * of one folder usually come from one album, so ties go to these.
     *
     * @var list<string>
     */
    private array $folderAlbums = [];

    public function __construct(
        private readonly HintExtractor $hintExtractor,
        private readonly MatchScorer $scorer,
        private readonly MusicBrainzClient $musicBrainz,
        private readonly AcoustIdClient $acoustId,
        private readonly Fingerprinter $fingerprinter,
        private readonly CoverArtClient $coverArt,
        private readonly string $musicDir,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    public function canFingerprint(): bool
    {
        return $this->acoustId->isConfigured() && $this->fingerprinter->isAvailable();
    }

    /**
     * Best proposal per target track.
     *
     * @param list<EnrichmentTrack> $tracks every indexed track of one folder; only `target` ones get proposals
     * @param callable(string):void $log
     *
     * @return list<Proposal>
     */
    public function enrichFolder(array $tracks, callable $log, ?string $root = null): array
    {
        return array_values(array_map(static fn(array $candidates): Proposal => $candidates[0], $this->identifyFolder($tracks, $log, $root)));
    }

    /**
     * Candidates per target track, best first. Doubtful tracks (best below
     * the confidence level) get up to MAX_CANDIDATES alternatives for a human
     * to choose from; confident ones a single candidate.
     *
     * @param list<EnrichmentTrack> $tracks every indexed track of one folder; only `target` ones get proposals
     * @param callable(string):void $log
     * @param ?string $root folder the paths are relative to for name hints (default MUSIC_DIR; the import folder for app:import)
     *
     * @return array<int, non-empty-list<Proposal>> music id => candidates
     */
    public function identifyFolder(array $tracks, callable $log, ?string $root = null): array
    {
        try {
            return $this->doIdentifyFolder($tracks, $log, $root ?? $this->musicDir);
        } catch (EnrichmentException|HttpException $e) {
            $log(\sprintf('  ! %s: %s', \dirname($tracks[0]->file ?? '?'), $e->getMessage()));

            return [];
        }
    }

    /**
     * @param list<EnrichmentTrack> $tracks
     * @param callable(string):void $log
     *
     * @return array<int, non-empty-list<Proposal>>
     */
    private function doIdentifyFolder(array $tracks, callable $log, string $root): array
    {
        /** @var array<int, EnrichmentTrack> $byId */
        $byId = [];
        /** @var array<int, TrackHints> $hints */
        $hints = [];
        foreach ($tracks as $track) {
            $byId[$track->musicId] = $track;
            $hints[$track->musicId] = $this->hintExtractor->extract($track->file, $root, $track->title, $track->artist, $track->album, $track->duration, $track->trackNumber);
        }
        $targets = array_keys(array_filter($byId, static fn(EnrichmentTrack $t): bool => $t->target));
        if ($targets === []) {
            return [];
        }
        $this->allowLive = $this->isLiveFolder($tracks, $hints);
        $this->folderAlbums = [];
        $this->albumAlternatives = [];

        /** @var array<int, list<RecordingMatch>> $acoustMatches music id => AcoustID matches */
        $acoustMatches = [];
        /** @var array<int, list<Proposal>> $candidates */
        $candidates = [];

        // Every file is fingerprinted (beets does the same): each one is then
        // checked individually instead of trusting a sample.
        foreach ($this->fingerprintTargets($targets, $hints) as $id) {
            $acoustMatches[$id] = $this->lookupFingerprint($byId[$id]);
        }

        if (\count($tracks) >= 2) {

            $match = null;
            for ($attempt = 1; $attempt <= 2; ++$attempt) {
                try {
                    $match = $this->bestAlbumMatch($tracks, $hints, $acoustMatches, $log);
                    break;
                } catch (EnrichmentException|HttpException $e) {
                    // MusicBrainz rate limiting is per IP, shared with any other
                    // client on the machine: pause once, then fall back to files
                    // one by one (much weaker for an album).
                    $log('  ! album lookup failed' . ($attempt === 1 ? ', retrying in 30 s' : '') . ': ' . $e->getMessage());
                    if ($attempt === 1) {
                        $this->clock->sleep(30);
                    }
                }
            }
            if ($match !== null) {
                $log(\sprintf('  = %s — %s (score %.2f, %d/%d aligned)', $match->release->artist, $match->release->title, $match->score, \count($match->assignments), \count($tracks)));
                // The runner-up albums are offered too (no extra lookup: already scored).
                foreach ([$match, ...$this->albumAlternatives] as $albumMatch) {
                    foreach ($targets as $id) {
                        $releaseTrack = $albumMatch->assignments[$id] ?? null;
                        if ($releaseTrack === null) {
                            continue;
                        }
                        $score = $albumMatch->score;
                        $breakdown = $albumMatch->breakdown;
                        // A partial folder (2 files of a 14-track album) scores low on
                        // track count; when the folders and the file name already say
                        // this artist, this album, this title, this number, it is sure.
                        if ($albumMatch === $match && !isset($match->breakdown['capped'])
                            && $this->namesAgree($hints[$id], $albumMatch->release, $releaseTrack)) {
                            $score = max($score, self::NAMES_AGREE_SCORE);
                            $breakdown['names_agree'] = 1.0;
                        }
                        $candidates[$id][] = $this->proposal($byId[$id], $albumMatch->release, $releaseTrack, $score, Proposal::SOURCE_ALBUM, $breakdown);
                    }
                }
            }
        }

        foreach ($targets as $id) {
            $album = $candidates[$id][0] ?? null;
            // The file's own fingerprint includes the recording the album gives
            // it: the album is confirmed, with the fingerprint's confidence
            // (otherwise another recording of the song — on a compilation, a
            // promo — could win alone). Albums held back for a close rival stay so.
            $confirmation = $album !== null && !isset($album->breakdown['capped'])
                ? $this->fingerprintConfirmation($album, $acoustMatches[$id] ?? [], $byId[$id]->duration)
                : null;
            if ($album !== null && $confirmation !== null) {
                $candidates[$id][0] = $album->withScore(max($album->score, $confirmation), ['fingerprint_confirms' => round($confirmation, 3)]);
                continue;
            }
            // A weak album guess is challenged by per-file identification for
            // files that carry a title or a fingerprint result (mixed folders,
            // home-made best-ofs, "Piste audio 07").
            if ($album !== null && ($album->score >= self::CONFIDENT
                || ($hints[$id]->bestTitle() === null && ($acoustMatches[$id] ?? []) === []))) {
                continue;
            }
            try {
                $orphans = $this->identifyOrphanCandidates($byId[$id], $hints[$id], $acoustMatches[$id] ?? null, $album === null || $album->score < self::CONFIDENT);
            } catch (EnrichmentException|HttpException $e) {
                // One failing lookup never costs the rest of the folder.
                $log(\sprintf('  ! %s: %s', basename($byId[$id]->file), $e->getMessage()));
                continue;
            }
            $candidates[$id] = $this->mergeCandidates($candidates[$id] ?? [], $orphans);
            $best = $candidates[$id][0] ?? null;
            if ($best !== null && $best !== $album && isset($best->fields['album'])) {
                $this->folderAlbums[] = (string) $best->fields['album'];
            }
        }

        // Covers only for the chosen candidate of tracks without one.
        $result = [];
        foreach ($candidates as $id => $list) {
            $list = $this->mergeCandidates($list, []);
            if ($list === []) {
                continue;
            }
            $best = $list[0];
            if (!$byId[$id]->hasImage && $best->releaseId !== null) {
                $list[0] = $best->withCover($this->coverCache[$best->releaseId] ??= $this->coverArt->front($best->releaseId, $best->releaseGroupId));
            }
            $result[$id] = $list;
        }

        return $result;
    }

    /**
     * The names the file carries — folder or tag artist, folder or tag album,
     * title, track number — all designate this release track, and its length
     * is consistent.
     */
    private function namesAgree(TrackHints $hints, ReleaseCandidate $release, ReleaseTrack $track): bool
    {
        $title = $hints->bestTitle();
        $artist = $hints->bestArtist() ?? $hints->folderArtist();
        $nearest = $hints->pathSegments[0] ?? null;
        $album = $hints->tagAlbum ?? ($nearest !== null ? (TrackHints::splitArtistDash($nearest)[1] ?? $nearest) : null);
        if ($title === null || $artist === null || $album === null) {
            return false;
        }
        if ($hints->trackNumber !== null && $hints->trackNumber !== $track->position) {
            return false;
        }
        if ($hints->duration > 0 && $track->duration !== null && abs($hints->duration - $track->duration) > 15) {
            return false;
        }

        return $this->scorer->similarity($title, $track->title) >= 0.85
            && $this->scorer->similarity($album, $release->title) >= 0.85
            && max($this->scorer->similarity($artist, $release->artist), $this->scorer->similarity($artist, $track->artist)) >= 0.85;
    }

    /**
     * AcoustID score of the album track's recording among the file's
     * fingerprint matches, null when the fingerprint doesn't include it — or
     * when that recording's length contradicts the file (AcoustID sometimes
     * links one fingerprint to several recordings).
     *
     * @param list<RecordingMatch> $matches
     */
    private function fingerprintConfirmation(Proposal $album, array $matches, int $duration): ?float
    {
        if ($album->recordingId === null) {
            return null;
        }
        foreach (\array_slice($matches, 0, self::ACOUSTID_RECORDINGS) as $match) {
            if ($match->id === $album->recordingId) {
                $lengthsAgree = $duration <= 0 || $match->duration === null || abs($match->duration - $duration) <= 15;

                return $lengthsAgree ? $match->score : null;
            }
        }

        return null;
    }

    /**
     * Concatenates, removes duplicates (same recording on the same release),
     * sorts by score (stable: on a tie the earlier source wins) and caps.
     *
     * @param list<Proposal> $a
     * @param list<Proposal> $b
     *
     * @return list<Proposal>
     */
    private function mergeCandidates(array $a, array $b): array
    {
        $unique = [];
        foreach ([...$a, ...$b] as $proposal) {
            $key = $proposal->key();
            if (!isset($unique[$key]) || $proposal->score > $unique[$key]->score) {
                $unique[$key] = $proposal;
            }
        }
        $list = array_values($unique);
        usort($list, static fn(Proposal $x, Proposal $y): int => $y->score <=> $x->score);

        return \array_slice($list, 0, self::MAX_CANDIDATES);
    }

    /**
     * Files to fingerprint, placeholder-titled ones first (names cannot help
     * them) when a huge folder hits the cap.
     *
     * @param list<int> $targets
     * @param array<int, TrackHints> $hints
     *
     * @return list<int>
     */
    private function fingerprintTargets(array $targets, array $hints): array
    {
        if (!$this->canFingerprint()) {
            return [];
        }
        usort($targets, static fn(int $a, int $b): int => (int) $hints[$b]->titleIsPlaceholder <=> (int) $hints[$a]->titleIsPlaceholder);

        return \array_slice($targets, 0, self::MAX_FINGERPRINTS);
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
        } catch (EnrichmentException|HttpException) {
            // A failed lookup (timeout, unreadable file) costs this file's
            // fingerprint only: names and the rest of the folder still count.
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
                array_map(static fn(RecordingMatch $m): string => $m->id, \array_slice($acoustMatches[$track->musicId] ?? [], 0, self::ACOUSTID_RECORDINGS)),
            );
        }
        $fingerprinted = array_filter($acoustMatches, static fn(array $m): bool => $m !== []);

        /** @var list<AlbumMatch> $matches */
        $matches = [];
        foreach (\array_slice($candidates, 0, self::MAX_RELEASE_LOOKUPS) as $releaseId) {
            $release = $this->musicBrainz->getRelease($releaseId);
            if ($release === null || $release->tracks === [] || $release->isUnwanted($this->allowLive)) {
                continue;
            }
            $matches[] = $this->scorer->score($folder, $release, $this->consensus($fingerprinted, $release));
        }
        usort($matches, static fn(AlbumMatch $a, AlbumMatch $b): int => [$b->score, (int) !$b->release->isCompilation] <=> [$a->score, (int) !$a->release->isCompilation]);
        $best = $matches[0] ?? null;
        if ($best !== null) {
            $best = $this->capDoubtful($best, $matches, $fingerprinted, $log);
            $alternatives = array_values(array_filter(
                \array_slice($matches, 1, 2),
                static fn(AlbumMatch $m): bool => $m->score >= self::ALBUM_FLOOR && $m->assignments !== [],
            ));
            // When the best album is held back (rival too close, fingerprints
            // disagreeing), its rivals are no surer: same ceiling.
            if (isset($best->breakdown['capped'])) {
                $alternatives = array_map(static fn(AlbumMatch $m): AlbumMatch => new AlbumMatch($m->release, min($m->score, $best->score), $m->assignments, $m->breakdown + ['capped' => 1.0]), $alternatives);
            }
            $this->albumAlternatives = $alternatives;
        }

        // Only a plausible guess may steer the other files of the folder.
        if ($best !== null && $best->score >= self::ALBUM_FLOOR) {
            $this->folderAlbums[] = $best->release->title;
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
     * Keeps a match out of auto-apply when fingerprints disagree with it, or
     * when a *different* album (another release group, not just another
     * edition) scores about as well.
     *
     * @param list<AlbumMatch> $matches all scored candidates, best first
     * @param array<int, list<RecordingMatch>> $fingerprinted
     * @param callable(string):void $log
     */
    private function capDoubtful(AlbumMatch $best, array $matches, array $fingerprinted, callable $log): AlbumMatch
    {
        $reason = null;
        $consensus = $this->consensus($fingerprinted, $best->release);
        if ($consensus !== null && \count($fingerprinted) >= 2 && $consensus < self::FINGERPRINT_MAJORITY) {
            $reason = \sprintf('only %d%% of the fingerprints agree', (int) round($consensus * 100));
        }
        foreach (\array_slice($matches, 1) as $rival) {
            $sameGroup = $best->release->releaseGroupId !== null && $rival->release->releaseGroupId === $best->release->releaseGroupId;
            if (!$sameGroup && $best->score - $rival->score < self::RIVAL_GAP) {
                $reason = \sprintf('"%s" by %s scores about as well', $rival->release->title, $rival->release->artist);
                break;
            }
        }
        if ($reason === null || $best->score < self::CONFIDENT) {
            return $best;
        }

        $log(\sprintf('  ? "%s" kept for review: %s', $best->release->title, $reason));

        return new AlbumMatch($best->release, min($best->score, self::CONFIDENT - 0.05), $best->assignments, $best->breakdown + ['capped' => 1.0]);
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
            foreach (\array_slice($matches, 0, self::ACOUSTID_RECORDINGS) as $match) {
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
        // Country editions of one album (same title, artist, track count) score
        // identically: one stands for all, so lookups go to genuinely different
        // editions (the 16-track one with a bonus track was crowded out by four
        // identical 15-track ones).
        /** @var array<string, string> $editions edition key => representative release id */
        $editions = [];
        $add = static function (ReleaseSummary $release, float $weight) use (&$weights, &$editions): void {
            if ($release->id === '') {
                return;
            }
            $key = $release->trackCount > 0 && $release->title !== ''
                ? mb_strtolower($release->title . "\0" . $release->artist . "\0" . $release->trackCount)
                : $release->id;
            $id = $editions[$key] ??= $release->id;
            $weights[$id] = ($weights[$id] ?? 0.0) + $weight;
        };

        foreach ($acoustMatches as $matches) {
            foreach (\array_slice($matches, 0, 3) as $match) {
                foreach ($this->rankReleases($match->releases, $size, null) as $release) {
                    $add($release, 3.0 * $match->score);
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
            foreach (\array_slice($this->distinctEditions($this->rankReleases($this->musicBrainz->searchReleases($artist, $album, self::RELEASE_SEARCH_LIMIT), $size, $album)), 0, 4) as $release) {
                $add($release, 2.0 * $release->score);
            }
        }

        // Titles from file names ("01 Papercut" in "Linkin Park/Album inconnu").
        $titled = array_filter($hints, static fn(TrackHints $h): bool => $h->bestTitle() !== null && $h->artistGuess() !== null);
        foreach (\array_slice($titled, 0, 3) as $h) {
            $title = (string) $h->bestTitle();
            $recordings = array_filter(
                $this->musicBrainz->searchRecordings($h->artistGuess(), $title, self::RECORDING_SEARCH_LIMIT, $this->excludeLive($title)),
                static fn(RecordingMatch $r): bool => !$r->isVariantOf($title),
            );
            foreach (\array_slice($recordings, 0, 2) as $recording) {
                foreach (\array_slice($this->distinctEditions($this->rankReleases($recording->releases, $size, null, $recording->artist)), 0, 3) as $release) {
                    $add($release, $recording->score);
                }
            }
        }

        // Highest weight first, id as a stable tie-break.
        uksort($weights, static fn(string $a, string $b): int => [$weights[$b], $a] <=> [$weights[$a], $b]);

        return array_keys($weights);
    }

    /**
     * Keeps the first of each group of equivalent editions (same title,
     * artist and track count), preserving the order.
     *
     * @param list<ReleaseSummary> $releases
     *
     * @return list<ReleaseSummary>
     */
    private function distinctEditions(array $releases): array
    {
        $seen = [];
        $out = [];
        foreach ($releases as $release) {
            $key = $release->trackCount > 0 && $release->title !== ''
                ? mb_strtolower($release->title . "\0" . $release->artist . "\0" . $release->trackCount)
                : $release->id;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $release;
            }
        }

        return $out;
    }

    /**
     * Orders releases by preference: official studio album first, close to
     * the folder's file count, similar title, oldest edition.
     *
     * @param list<ReleaseSummary> $releases
     *
     * @return list<ReleaseSummary>
     */
    private function rankReleases(array $releases, int $fileCount, ?string $albumHint, ?string $artist = null): array
    {
        $releases = array_values(array_filter($releases, fn(ReleaseSummary $r): bool => !$r->isUnwanted($this->allowLive)));
        $rank = fn(ReleaseSummary $r): float => $this->releaseRank($r, $fileCount, $albumHint, $artist);
        // Id as last tie-break: MusicBrainz returns equal-score results in no
        // stable order, and the outcome must not depend on it.
        usort($releases, static fn(ReleaseSummary $a, ReleaseSummary $b): int => [$rank($b), $a->date ?? '9999', $a->id] <=> [$rank($a), $b->date ?? '9999', $b->id]);

        return $releases;
    }

    private function releaseRank(ReleaseSummary $r, int $fileCount, ?string $albumHint, ?string $artist = null): float
    {
        $value = (float) $r->preferenceRank();
        // A release with fewer tracks than the folder cannot hold it; one with
        // more is normal (partial album, bonus tracks): barely penalised.
        if ($r->trackCount > 0) {
            $value -= $r->trackCount < $fileCount
                ? min(4, ($fileCount - $r->trackCount) / 2)
                : min(1, ($r->trackCount - $fileCount) / 15);
        }
        // The artist's own record beats various-artists demo CDs and samplers.
        if ($artist !== null && $artist !== '' && $r->artist !== '' && $this->scorer->similarity($r->artist, $artist) >= 0.85) {
            $value += 4;
        }
        // The folder's own name weighs more than what other files suggested.
        if ($albumHint !== null && $this->scorer->similarity($r->title, $albumHint) >= 0.85) {
            $value += 6;
        }
        foreach ($this->folderAlbums as $album) {
            if ($this->scorer->similarity($r->title, $album) >= 0.85) {
                $value += 4;
                break;
            }
        }
        // Single track: older first, the original album predates compilations
        // and reissues (1977 → +2.65, 1990 → +2, 2020 → +0.5). Not for a
        // folder: there the edition whose tracklist matches must win.
        if ($fileCount === 1 && $r->date !== null && preg_match('/^(\d{4})/', $r->date, $m) === 1) {
            $value += max(0, 2030 - (int) $m[1]) / 20;
        }

        return $value;
    }

    private function excludeLive(string $title): bool
    {
        return !$this->allowLive && stripos($title, 'live') === false;
    }

    /**
     * Identifies one file on its own: fingerprint first, then the names it
     * carries. When the best candidate is doubtful and $alternatives is set,
     * the runner-up fingerprint recordings and name-search results are added.
     *
     * @param ?list<RecordingMatch> $acoustMatches already looked up in the album pass, null if not
     *
     * @return list<Proposal> best first
     */
    private function identifyOrphanCandidates(EnrichmentTrack $track, TrackHints $hints, ?array $acoustMatches, bool $alternatives): array
    {
        $acoustMatches ??= $this->lookupFingerprint($track);
        $picked = $this->pickFingerprintMatches($track, $hints, $acoustMatches);

        $out = [];
        foreach ($picked as $i => [$recording, $score, $breakdown]) {
            if ($i > 0 && (!$alternatives || $out[0]->score >= self::CONFIDENT || $i >= self::MAX_CANDIDATES)) {
                break;
            }
            $out[] = $this->proposalForRecording($track, $hints, $recording, $score, Proposal::SOURCE_ACOUSTID, $breakdown);
        }

        $doubtful = $out === [] || $out[0]->score < self::CONFIDENT;
        if ($out === [] || ($doubtful && $alternatives)) {
            $found = $this->searchCandidates($track, $hints);
            foreach (\array_slice($found, 0, $alternatives ? self::MAX_CANDIDATES : 1) as [$recording, $score, $breakdown]) {
                // The audio was fingerprinted as something else: names alone
                // cannot settle it, a human must.
                if ($picked !== []) {
                    $score = min($score, self::CONFIDENT - 0.05);
                    $breakdown['fingerprint_disagrees'] = 1.0;
                }
                $out[] = $this->proposalForRecording($track, $hints, $recording, $score, Proposal::SOURCE_SEARCH, $breakdown);
            }
        }

        return $this->mergeCandidates($out, []);
    }

    /**
     * Name-search candidates, retrying "04 Numb - Encore" in "Linkin Park/"
     * (the left part was half of the title, not an artist) with the folder's
     * artist.
     *
     * @return list<array{0: RecordingMatch, 1: float, 2: array<string, float>}>
     */
    private function searchCandidates(EnrichmentTrack $track, TrackHints $hints): array
    {
        $title = $hints->bestTitle();
        $artist = $hints->artistGuess();
        if ($title === null || $artist === null) {
            return [];
        }

        $found = $this->searchRecordingMatches($track, $hints, $artist, $title);
        $folderArtist = $hints->folderArtist();
        if ($found === [] && $hints->filenameArtist !== null && $folderArtist !== null
            && mb_strtolower($folderArtist) !== mb_strtolower($hints->filenameArtist)) {
            $found = $this->searchRecordingMatches($track, $hints, $folderArtist, $hints->filenameArtist . ' ' . $title);
        }

        return $found;
    }

    /**
     * @return list<array{0: RecordingMatch, 1: float, 2: array<string, float>}> best first, above ALBUM_FLOOR
     */
    private function searchRecordingMatches(EnrichmentTrack $track, TrackHints $hints, string $artist, string $title): array
    {
        $albumHint = $hints->tagAlbum ?? ($hints->pathSegments[0] ?? null);
        $found = [];
        foreach ($this->searchRecordingsForgiving($artist, $title) as $recording) {
            // Live performances are separate recordings, released on bootlegs only.
            $ranked = $this->rankReleases($recording->releases, 1, $albumHint, $recording->artist);
            if ($recording->releases !== [] && $ranked === []) {
                continue;
            }
            // Equal scores: the recording found on the better album wins (the
            // studio album, or the one this folder's other files matched).
            $quality = $ranked !== [] ? $this->releaseRank($ranked[0], 1, $albumHint, $recording->artist) : -100.0;
            $titleSim = $this->scorer->similarity($title, $recording->title);
            $artistSim = $this->scorer->similarity($artist, $recording->artist);
            $duration = $this->durationFactor($track->duration, $recording->duration);
            $score = round(0.45 * $titleSim + 0.35 * $artistSim + 0.20 * $duration, 3);
            if ($recording->isVariantOf($title)) {
                $score = round($score * 0.6, 3);
            }
            if ($score >= self::ALBUM_FLOOR) {
                $found[] = [$recording, $score, ['title' => round($titleSim, 3), 'artist' => round($artistSim, 3), 'duration' => round($duration, 3)], $quality];
            }
        }
        usort($found, static fn(array $a, array $b): int => [$b[1], $b[3]] <=> [$a[1], $a[3]]);

        // One candidate per recording title/artist (searches list many duplicates).
        $unique = [];
        foreach ($found as [$recording, $score, $breakdown]) {
            $unique[mb_strtolower($recording->title . "\0" . $recording->artist)] ??= [$recording, $score, $breakdown];
        }

        return array_values($unique);
    }

    /**
     * Exact phrase first; when it finds nothing ("Papa Roach - Last Ressort"),
     * every word fuzzy; then the title alone, keeping only recordings by a
     * similar artist ("Sum41 - Fat Lip" → Sum 41). The usual scoring then
     * weighs title, artist and length as for an exact hit.
     *
     * @return list<RecordingMatch>
     */
    private function searchRecordingsForgiving(string $artist, string $title): array
    {
        $excludeLive = $this->excludeLive($title);
        $found = $this->musicBrainz->searchRecordings($artist, $title, self::RECORDING_SEARCH_LIMIT, $excludeLive);
        if ($found === []) {
            $found = $this->musicBrainz->searchRecordingsFuzzy($artist, $title, self::RECORDING_SEARCH_LIMIT, $excludeLive);
        }
        if ($found === []) {
            $found = array_values(array_filter(
                $this->musicBrainz->searchRecordingsFuzzy(null, $title, self::RECORDING_SEARCH_LIMIT, $excludeLive),
                fn(RecordingMatch $r): bool => $this->scorer->similarity($artist, $r->artist) >= self::TITLE_ONLY_MIN_ARTIST,
            ));
        }

        return $found;
    }

    /**
     * Chooses among the recordings AcoustID returned for one fingerprint.
     * Several recordings share a fingerprint (album take, remix, demo,
     * remaster): the best candidates are looked up on MusicBrainz for their
     * disambiguation, variants the file name doesn't ask for are demoted, the
     * length must agree, and a result contradicting both the title and the
     * artist the file carries is never auto-applied (AcoustID does err).
     *
     * @param list<RecordingMatch> $matches
     *
     * @return list<array{0: RecordingMatch, 1: float, 2: array<string, float>}> best first, empty when nothing is usable
     */
    private function pickFingerprintMatches(EnrichmentTrack $track, TrackHints $hints, array $matches): array
    {
        $top = $matches[0]->score ?? 0.0;
        $title = $hints->bestTitle();

        $candidates = [];
        foreach (\array_slice($matches, 0, self::FINGERPRINT_CHOICES) as $match) {
            if ($match->score < $top - 0.1) {
                break;
            }
            $candidates[] = [$match, $this->musicBrainz->getRecording($match->id) ?? $match];
        }
        // Live takes, remixes, demos are only demoted when a plain take shares
        // the fingerprint; on a live album the live take is the answer.
        $hasPlain = $title === null || array_filter($candidates, static fn(array $c): bool => !$c[1]->isVariantOf($title)) !== [];
        $albumHint = $hints->tagAlbum ?? ($hints->pathSegments[0] ?? null);

        $evaluated = [];
        foreach ($candidates as [$match, $recording]) {
            $duration = $this->durationFactor($track->duration, $recording->duration ?? $match->duration);
            $score = $match->score * $duration;
            $breakdown = ['acoustid' => round($match->score, 3), 'duration' => round($duration, 3)];

            if ($title !== null && $hasPlain && $recording->isVariantOf($title)) {
                $score *= 0.6;
                $breakdown['variant'] = 1.0;
            }
            // AcoustID does err ("Do I Wanna Know" → Foals, "I Miss You" →
            // Avril Lavigne, a karaoke cover of "Complicated"): a result the
            // file's own names contradict goes to review instead.
            [$titleSim, $artistSim] = $this->nameAgreement($hints, $recording);
            if (($titleSim !== null && $titleSim < 0.5) || ($artistSim !== null && $artistSim < 0.5)) {
                $score = min($score, self::CONFIDENT - 0.05);
                $breakdown['names_disagree'] = 1.0;
            }

            $ranked = $this->rankReleases($recording->releases, 1, $albumHint, $recording->artist);
            $quality = $ranked !== [] ? $this->releaseRank($ranked[0], 1, $albumHint, $recording->artist) : -100.0;
            $evaluated[] = [$recording, round($score, 3), $breakdown, $quality, $titleSim ?? 0.0];
        }

        // Highest score; recordings within FINGERPRINT_TIE are ties, settled by
        // the album they appear on (the artist's own, original album beats a
        // promo, a compilation or a later reissue).
        usort($evaluated, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
        $best = null;
        foreach ($evaluated as $candidate) {
            if ($best === null) {
                $best = $candidate;
            } elseif ($best[1] - $candidate[1] <= self::FINGERPRINT_TIE && [$candidate[3], $candidate[4]] > [$best[3], $best[4]]) {
                $best = $candidate;
            }
        }

        // A fingerprint whose length is far off is no identification.
        if ($best === null || $best[1] < self::ALBUM_FLOOR) {
            return [];
        }

        $list = [[$best[0], $best[1], $best[2]]];
        foreach ($evaluated as $candidate) {
            if ($candidate !== $best && $candidate[1] >= self::ALBUM_FLOOR) {
                $list[] = [$candidate[0], $candidate[1], $candidate[2]];
            }
        }

        return $list;
    }

    /**
     * How well a recording agrees with the names the file carries: best
     * similarity over every candidate title/artist (tags, "Artist - Title"
     * and "Title - Artist" file names, the folder), artist credits split
     * ("Angèle & Justice" contains "Angèle"). Null when the file names none.
     *
     * @return array{0: ?float, 1: ?float} title, artist
     */
    private function nameAgreement(TrackHints $hints, RecordingMatch $recording): array
    {
        $titleSim = null;
        if ($hints->bestTitle() !== null) {
            foreach (array_filter([$hints->tagTitle, $hints->filenameTitle, $hints->filenameArtist]) as $name) {
                $titleSim = max($titleSim ?? 0.0, $this->scorer->similarity((string) $name, $recording->title));
            }
        }

        $artistSim = null;
        // Only an artist the file itself names (tag, file name) can contradict.
        if (($hints->tagArtist !== null || $hints->filenameArtist !== null) && $recording->artist !== '') {
            $credits = array_merge([$recording->artist], preg_split('/\s*(?:&|,|\/|\bfeat\.?|\bft\.?|\bx\b|\band\b)\s*/iu', $recording->artist) ?: []);
            foreach (array_filter([$hints->tagArtist, $hints->filenameArtist, $hints->filenameTitle, $hints->folderArtist()]) as $name) {
                foreach (array_filter($credits) as $credit) {
                    $artistSim = max($artistSim ?? 0.0, $this->scorer->similarity((string) $name, (string) $credit));
                }
            }
        }

        return [$titleSim, $artistSim];
    }

    /**
     * @param array<string, float> $breakdown
     */
    private function proposalForRecording(EnrichmentTrack $track, TrackHints $hints, RecordingMatch $recording, float $score, string $source, array $breakdown): Proposal
    {
        $albumHint = $hints->tagAlbum ?? ($hints->pathSegments[0] ?? null);
        foreach (\array_slice($this->rankReleases($recording->releases, 1, $albumHint, $recording->artist), 0, self::RELEASES_PER_RECORDING) as $summary) {
            $release = $this->musicBrainz->getRelease($summary->id);
            if ($release === null || $release->isUnwanted($this->allowLive)) {
                continue;
            }
            foreach ($release->tracks as $releaseTrack) {
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

        // The cover is fetched later, for the chosen candidate only.
        return new Proposal($track->musicId, $fields, round($score, 3), $source, $release->id, $releaseTrack->recordingId, $breakdown, null, $release->releaseGroupId);
    }

    /**
     * @param list<EnrichmentTrack> $tracks
     * @param array<int, TrackHints> $hints
     */
    private function isLiveFolder(array $tracks, array $hints): bool
    {
        foreach ($tracks as $track) {
            $h = $hints[$track->musicId];
            $text = implode(' ', array_filter([$h->tagAlbum, ...$h->pathSegments]));
            if (preg_match('/\b(?:live|concert|unplugged|en public)\b/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function durationFactor(int $local, ?int $remote): float
    {
        if ($local <= 0 || $remote === null) {
            return 0.7;
        }

        return max(0.0, min(1.0, 1 - (abs($local - $remote) - 3) / 10));
    }
}
