<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

final readonly class Fingerprint
{
    public function __construct(
        public string $fingerprint,
        /** Seconds, as measured by fpcalc. */
        public int $duration,
    ) {}
}
