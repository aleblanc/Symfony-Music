<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** A MusicBrainz recording (a song performance) found by AcoustID or a search. */
final readonly class RecordingMatch
{
    /**
     * @param list<ReleaseSummary> $releases releases containing this recording (may be ids only when from AcoustID)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        /** Seconds. */
        public ?int $duration,
        /** Confidence 0..1 (AcoustID score or MusicBrainz search score). */
        public float $score,
        public array $releases,
        /** MusicBrainz disambiguation comment ("live, 2004-08-03: …", "remix"). */
        public string $disambiguation = '',
    ) {}

    /**
     * True for a live take, remix, demo… the file title doesn't ask for: the
     * qualifier appears in this recording's title or disambiguation only.
     */
    public function isVariantOf(string $fileTitle): bool
    {
        $pattern = '/\b(live|remix|mix|demo|instrumental|acoustic|karaoke|reprise|rehearsal|session|unplugged|video|extended|club|dub|edit)\b/iu';
        if (preg_match_all($pattern, $this->title . ' ' . $this->disambiguation, $m) === 0) {
            return false;
        }
        foreach ($m[1] as $qualifier) {
            if (stripos($fileTitle, $qualifier) === false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function releaseIds(): array
    {
        return array_map(static fn(ReleaseSummary $r): string => $r->id, $this->releases);
    }
}
