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
     * One-to-one alignment, most reliable signal first: AcoustID recording id,
     * track number (with a sane duration), title, then nearest duration.
     *
     * @param list<FolderTrack> $files
     *
     * @return array<int, ReleaseTrack>
     */
    private function align(array $files, ReleaseCandidate $release): array
    {
        $assigned = [];
        $taken = [];
        $multiDisc = $release->discCount() > 1;

        $assign = static function (FolderTrack $file, int $index, ReleaseTrack $track) use (&$assigned, &$taken): void {
            $assigned[$file->musicId] = $track;
            $taken[$index] = true;
        };
        $free = function (callable $accept) use ($release, &$taken): ?int {
            foreach ($release->tracks as $index => $track) {
                if (!isset($taken[$index]) && $accept($track)) {
                    return $index;
                }
            }

            return null;
        };

        foreach ($files as $file) {
            if ($file->recordingIds === []) {
                continue;
            }
            $index = $free(static fn(ReleaseTrack $t): bool => $t->recordingId !== null && \in_array($t->recordingId, $file->recordingIds, true));
            if ($index !== null) {
                $assign($file, $index, $release->tracks[$index]);
            }
        }

        foreach ($files as $file) {
            $number = $file->hints->trackNumber;
            if (isset($assigned[$file->musicId]) || $number === null) {
                continue;
            }
            $disc = $file->hints->discNumber ?? 1;
            $index = $free(fn(ReleaseTrack $t): bool => $t->position === $number
                && (!$multiDisc || $t->disc === $disc)
                && $this->drift($file, $t) <= self::DURATION_NUMBER_MAX);
            if ($index !== null) {
                $assign($file, $index, $release->tracks[$index]);
            }
        }

        foreach ($files as $file) {
            $title = $file->hints->bestTitle();
            if (isset($assigned[$file->musicId]) || $title === null) {
                continue;
            }
            $best = null;
            $bestSim = 0.85;
            foreach ($release->tracks as $index => $track) {
                if (isset($taken[$index])) {
                    continue;
                }
                $sim = $this->similarity($title, $track->title);
                if ($sim >= $bestSim) {
                    [$best, $bestSim] = [$index, $sim];
                }
            }
            if ($best !== null) {
                $assign($file, $best, $release->tracks[$best]);
            }
        }

        $pairs = [];
        foreach ($files as $file) {
            if (isset($assigned[$file->musicId]) || $file->hints->duration <= 0) {
                continue;
            }
            foreach ($release->tracks as $index => $track) {
                if (!isset($taken[$index]) && $track->duration !== null) {
                    $drift = abs($track->duration - $file->hints->duration);
                    if ($drift <= self::DURATION_ALIGN_MAX) {
                        $pairs[] = [$drift, $file, $index];
                    }
                }
            }
        }
        usort($pairs, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
        foreach ($pairs as [, $file, $index]) {
            if (!isset($assigned[$file->musicId]) && !isset($taken[$index])) {
                $assign($file, $index, $release->tracks[$index]);
            }
        }

        return $assigned;
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
