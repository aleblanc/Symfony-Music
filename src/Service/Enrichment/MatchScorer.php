<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/**
 * Scores how well a MusicBrainz release explains a folder of local files and
 * aligns each file to a release track.
 *
 * Score (0..1) = 0.40 duration fit + 0.25 AcoustID consensus + 0.20 track
 * count fit + 0.15 name similarity. Without fingerprints the AcoustID weight
 * is dropped and the rest renormalised, so durations + names alone can still
 * clear the auto-apply threshold for a well-named folder.
 */
final class MatchScorer
{
    private const W_DURATION = 0.40;
    private const W_ACOUSTID = 0.25;
    private const W_COUNT = 0.20;
    private const W_NAME = 0.15;

    /** Seconds of drift that still count as a perfect duration match. */
    private const DURATION_EXACT = 2;
    /** Max drift accepted when aligning purely by duration. */
    private const DURATION_ALIGN_MAX = 4;
    /** Max drift accepted when a track number already agrees. */
    private const DURATION_NUMBER_MAX = 10;
    /** Below this title similarity, a titled file is never aligned to the track. */
    private const TITLE_CONTRADICTION = 0.5;
    /** Cost above which a file is better left unaligned. */
    private const UNASSIGNED_COST = 2.0;
    /** Added when a fingerprinted file's recordings don't include the track's: enough to leave it unaligned unless its title agrees. */
    private const FINGERPRINT_MISMATCH = 2.0;
    /** Cost of an impossible pair (finite so the algorithm stays numeric). */
    private const FORBIDDEN = 1.0e6;

    private ?\Transliterator $transliterator = null;

    /**
     * @param list<FolderTrack> $files
     * @param ?float $acoustIdConsensus share of fingerprinted files whose AcoustID results include this release, null if nothing was fingerprinted
     */
    public function score(array $files, ReleaseCandidate $release, ?float $acoustIdConsensus): AlbumMatch
    {
        $assignments = $this->align($files, $release);

        $duration = $this->durationFit($files, $assignments);
        $count = $this->countFit(\count($files), $release->trackCount());
        $name = $this->nameFit($files, $release);

        if ($acoustIdConsensus === null) {
            $score = (self::W_DURATION * $duration + self::W_COUNT * $count + self::W_NAME * $name)
                / (self::W_DURATION + self::W_COUNT + self::W_NAME);
        } else {
            $score = self::W_DURATION * $duration + self::W_ACOUSTID * $acoustIdConsensus
                + self::W_COUNT * $count + self::W_NAME * $name;
        }

        return new AlbumMatch($release, round($score, 3), $assignments, [
            'duration' => round($duration, 3),
            'acoustid' => $acoustIdConsensus !== null ? round($acoustIdConsensus, 3) : -1.0,
            'count' => round($count, 3),
            'name' => round($name, 3),
        ]);
    }

    /**
     * Normalised similarity between two names (0..1): case, accents and
     * punctuation are ignored; containment ("OK Computer" in "Radiohead - OK
     * Computer") scores 0.9.
     */
    public function similarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $short = \strlen($a) <= \strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;
        if (\strlen($short) >= 3 && str_contains(" {$long} ", " {$short} ")) {
            return 0.9;
        }
        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /**
     * One-to-one alignment solved optimally over a cost matrix (Hungarian
     * algorithm, as beets does) instead of a greedy cascade, which strands
     * files when durations are close (200/205 s vs 203/208 s).
     *
     * Pair cost: 0 for an AcoustID recording match; otherwise title distance
     * + duration drift + track-number agreement. Impossible pairs (a titled
     * file vs another song, a known number vs another number, a drift no
     * signal can justify) are forbidden; a file whose best option costs more
     * than UNASSIGNED_COST stays unaligned.
     *
     * @param list<FolderTrack> $files
     *
     * @return array<int, ReleaseTrack>
     */
    private function align(array $files, ReleaseCandidate $release): array
    {
        $tracks = $release->tracks;
        if ($files === [] || $tracks === []) {
            return [];
        }

        $multiDisc = $release->discCount() > 1;
        // Rips of multi-disc sets are often numbered continuously (disc 2
        // track 1 = "11" when disc 1 has 10 tracks): accept both numberings.
        $numberFits = static function (FolderTrack $file, int $index, ReleaseTrack $t) use ($multiDisc): bool {
            $number = $file->hints->trackNumber;
            if ($number === null) {
                return false;
            }
            if ($t->position === $number && (!$multiDisc || $t->disc === ($file->hints->discNumber ?? 1))) {
                return true;
            }

            return $multiDisc && $file->hints->discNumber === null && $index + 1 === $number;
        };

        $cost = [];
        foreach ($files as $f => $file) {
            // A file whose number exists on the release must land on that number.
            $pinned = false;
            foreach ($tracks as $index => $t) {
                if ($numberFits($file, $index, $t)) {
                    $pinned = true;
                    break;
                }
            }
            foreach ($tracks as $index => $t) {
                $cost[$f][$index] = $this->pairCost($file, $index, $t, $numberFits($file, $index, $t), $pinned);
            }
        }

        $assigned = [];
        foreach ($this->hungarian($cost, \count($files), \count($tracks)) as $f => $index) {
            if ($cost[$f][$index] < self::UNASSIGNED_COST) {
                $assigned[$files[$f]->musicId] = $tracks[$index];
            }
        }

        return $assigned;
    }

    private function pairCost(FolderTrack $file, int $index, ReleaseTrack $t, bool $numberFits, bool $pinned): float
    {
        if ($t->recordingId !== null && \in_array($t->recordingId, $file->recordingIds, true)) {
            return 0.0;
        }
        if ($pinned && !$numberFits) {
            return self::FORBIDDEN;
        }

        $cost = 0.0;
        // The file's fingerprint names other recordings: almost certainly not
        // this track, whatever its length or number says.
        if ($file->recordingIds !== [] && $t->recordingId !== null) {
            $cost += self::FINGERPRINT_MISMATCH;
        }
        $title = $file->hints->bestTitle();
        if ($title !== null) {
            $sim = $this->similarity($title, $t->title);
            if ($sim < self::TITLE_CONTRADICTION) {
                return self::FORBIDDEN;
            }
            $cost += (1 - $sim) * 2;
        }

        $known = $file->hints->duration > 0 && $t->duration !== null;
        $drift = $this->drift($file, $t);
        // Without a title, only a matching number or a near-exact length can
        // justify the pair.
        $maxDrift = $title !== null ? 30 : ($numberFits ? self::DURATION_NUMBER_MAX : self::DURATION_ALIGN_MAX);
        if ($known && $drift > $maxDrift) {
            return self::FORBIDDEN;
        }
        $cost += $known ? min(1.0, max(0.0, ($drift - self::DURATION_EXACT) / 8)) * 1.5 : 0.6;

        if ($file->hints->trackNumber !== null) {
            $cost += $numberFits ? 0.0 : 0.5;
        } else {
            $cost += 0.25;
        }

        return $cost;
    }

    /**
     * Minimum-cost assignment (Hungarian algorithm with potentials, O(n³)).
     * The matrix is squared with "unassigned" slots so a file can stay
     * unaligned at UNASSIGNED_COST and extra tracks cost nothing.
     *
     * @param array<int, array<int, float>> $cost rows = files, cols = tracks
     *
     * @return array<int, int> file row => track column (real tracks only)
     */
    private function hungarian(array $cost, int $rows, int $cols): array
    {
        $n = $rows + $cols;
        $a = [];
        for ($i = 1; $i <= $n; ++$i) {
            for ($j = 1; $j <= $n; ++$j) {
                if ($i <= $rows && $j <= $cols) {
                    $a[$i][$j] = $cost[$i - 1][$j - 1];
                } elseif ($i <= $rows) {
                    $a[$i][$j] = self::UNASSIGNED_COST;
                } else {
                    $a[$i][$j] = 0.0;
                }
            }
        }

        $u = array_fill(0, $n + 1, 0.0);
        $v = array_fill(0, $n + 1, 0.0);
        $p = array_fill(0, $n + 1, 0);
        $way = array_fill(0, $n + 1, 0);
        for ($i = 1; $i <= $n; ++$i) {
            $p[0] = $i;
            $j0 = 0;
            $minv = array_fill(0, $n + 1, \INF);
            $used = array_fill(0, $n + 1, false);
            do {
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = \INF;
                $j1 = 0;
                for ($j = 1; $j <= $n; ++$j) {
                    if (!$used[$j]) {
                        $cur = $a[$i0][$j] - $u[$i0] - $v[$j];
                        if ($cur < $minv[$j]) {
                            $minv[$j] = $cur;
                            $way[$j] = $j0;
                        }
                        if ($minv[$j] < $delta) {
                            $delta = $minv[$j];
                            $j1 = $j;
                        }
                    }
                }
                for ($j = 0; $j <= $n; ++$j) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } else {
                        $minv[$j] -= $delta;
                    }
                }
                $j0 = $j1;
            } while ($p[$j0] !== 0);
            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }

        $result = [];
        for ($j = 1; $j <= $cols; ++$j) {
            if ($p[$j] >= 1 && $p[$j] <= $rows) {
                $result[$p[$j] - 1] = $j - 1;
            }
        }

        return $result;
    }

    /**
     * @param list<FolderTrack> $files
     * @param array<int, ReleaseTrack> $assignments
     */
    private function durationFit(array $files, array $assignments): float
    {
        if ($files === []) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($files as $file) {
            $track = $assignments[$file->musicId] ?? null;
            if ($track === null) {
                continue;
            }
            if ($file->hints->duration <= 0 || $track->duration === null) {
                $total += 0.5;
                continue;
            }
            $total += max(0.0, min(1.0, 1 - ($this->drift($file, $track) - self::DURATION_EXACT) / 8));
        }

        return $total / \count($files);
    }

    private function countFit(int $files, int $tracks): float
    {
        if ($files === 0 || $tracks === 0) {
            return 0.0;
        }
        if ($files <= $tracks) {
            return $files / $tracks;
        }

        // More files than tracks: this release cannot explain the folder.
        return max(0.0, 1 - ($files - $tracks) / $tracks) * 0.5;
    }

    /**
     * @param list<FolderTrack> $files
     */
    private function nameFit(array $files, ReleaseCandidate $release): float
    {
        $names = [];
        foreach ($files as $file) {
            foreach ($file->hints->nameCandidates() as $name) {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return 0.0;
        }

        $title = $artist = 0.0;
        foreach (array_keys($names) as $name) {
            $title = max($title, $this->similarity($release->title, (string) $name));
            $artist = max($artist, $this->similarity($release->artist, (string) $name));
        }

        return ($title + $artist) / 2;
    }

    /**
     * A file whose name carries a real title cannot be another song, however
     * close its length or number ("10 Forgotten" is not "Faint").
     */
    private function contradicts(FolderTrack $file, ReleaseTrack $track): bool
    {
        $title = $file->hints->bestTitle();

        return $title !== null && $this->similarity($title, $track->title) < self::TITLE_CONTRADICTION;
    }

    private function drift(FolderTrack $file, ReleaseTrack $track): int
    {
        if ($file->hints->duration <= 0 || $track->duration === null) {
            return 0;
        }

        return abs($track->duration - $file->hints->duration);
    }

    private function normalize(string $value): string
    {
        $this->transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $value = $this->transliterator?->transliterate($value) ?: mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim($value);
    }
}
