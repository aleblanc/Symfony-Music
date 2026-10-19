<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** A remote lookup or tool invocation failed; the current folder is skipped, the run continues. */
final class EnrichmentException extends \RuntimeException
{
}
