<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use App\Service\BinaryLocator;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Computes Chromaprint fingerprints with the `fpcalc` binary
 * (macOS: `brew install chromaprint`).
 *
 * The binary is located automatically: FPCALC_PATH when it points to an
 * executable, then the PATH, then well-known install dirs — a web server's
 * PATH usually lacks Homebrew's /opt/homebrew/bin.
 */
class Fingerprinter
{
    public const SEARCH_DIRS = BinaryLocator::SEARCH_DIRS;

    private ?bool $available = null;
    private string|false|null $binary = false;

    /**
     * @param list<string> $searchDirs dirs tried after the PATH (~/.local/bin and ~/bin are added)
     */
    public function __construct(
        private readonly string $fpcalcPath = '',
        private readonly array $searchDirs = self::SEARCH_DIRS,
    ) {}

    /** Absolute path of the fpcalc binary, or null when it is not installed. */
    public function binaryPath(): ?string
    {
        if ($this->binary !== false) {
            return $this->binary;
        }

        return $this->binary = (new BinaryLocator())->find('fpcalc', $this->fpcalcPath, $this->searchDirs);
    }

    public function isAvailable(): bool
    {
        if ($this->available === null) {
            $binary = $this->binaryPath();
            if ($binary === null) {
                return $this->available = false;
            }
            try {
                $process = new Process([$binary, '-version']);
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
        $binary = $this->binaryPath()
            ?? throw new EnrichmentException('fpcalc not found: install Chromaprint (brew install chromaprint / apt install libchromaprint-tools) or set FPCALC_PATH.');

        // 120 s of audio is what AcoustID indexes; analysing more is wasted time.
        $process = new Process([$binary, '-json', '-length', '120', $file]);
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
