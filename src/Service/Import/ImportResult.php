<?php

namespace App\Service\Import;

final readonly class ImportResult
{
    public const IMPORTED = 'imported';
    public const DUPLICATE = 'duplicate';

    public function __construct(
        /** imported | duplicate */
        public string $status,
        public string $destination,
    ) {}
}
