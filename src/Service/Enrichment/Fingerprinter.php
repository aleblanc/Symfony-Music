<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Computes Chromaprint fingerprints with the `fpcalc` binary
 * (macOS: `brew install chromaprint`). Configure its path with FPCALC_PATH
 * when the web server's PATH does not include Homebrew.
 */
class Fingerprinter
{
    private ?bool $available = null;

    public function __construct(
        private readonly string $fpcalcPath,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->available === null) {
            try {
                $process = new Process([$this->fpcalcPath, '-version']);
                $process->setTimeout(10);
                $process->run();
                $this->available = $process->isSuccessful();
            } catch (ProcessException) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    /**
     * @throws EnrichmentException when fpcalc fails on this file
     */
    public function fingerprint(string $file): Fingerprint
    {
        // 120 s of audio is what AcoustID indexes; analysing more is wasted time.
        $process = new Process([$this->fpcalcPath, '-json', '-length', '120', $file]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (ProcessException $e) {
            throw new EnrichmentException('fpcalc failed: ' . $e->getMessage(), 0, $e);
        }
        if (!$process->isSuccessful()) {
            throw new EnrichmentException('fpcalc failed: ' . trim($process->getErrorOutput()));
        }

        $data = json_decode($process->getOutput(), true);
        if (!\is_array($data) || !isset($data['fingerprint'], $data['duration'])) {
            throw new EnrichmentException('fpcalc returned unexpected output.');
        }

        return new Fingerprint((string) $data['fingerprint'], (int) round((float) $data['duration']));
    }
}
