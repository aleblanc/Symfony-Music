<?php

/*
 * Standalone test for Enrichment\Fingerprinter's fpcalc lookup: explicit
 * FPCALC_PATH, then PATH, then well-known install dirs (the web server's PATH
 * usually lacks Homebrew's /opt/homebrew/bin). Uses a fake fpcalc script.
 *
 * Run:  php tests/enrichment-fingerprinter-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Enrichment\EnrichmentException;
use App\Service\Enrichment\Fingerprinter;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ok  {$label}\n";
    } else {
        ++$failures;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$dir = sys_get_temp_dir() . '/fpcalc-test-' . bin2hex(random_bytes(4));
mkdir($dir);
$fake = "{$dir}/fpcalc";
file_put_contents($fake, <<<'SH'
    #!/bin/sh
    if [ "$1" = "-version" ]; then echo "fpcalc version 1.5.1"; exit 0; fi
    echo '{"duration": 117.3, "fingerprint": "AQAAfake"}'
    SH);
chmod($fake, 0755);

$originalPath = (string) getenv('PATH');
putenv('PATH=/nonexistent-dir');

try {
    check('default search dirs include Homebrew (Apple Silicon + Intel)', in_array('/opt/homebrew/bin', Fingerprinter::SEARCH_DIRS, true) && in_array('/usr/local/bin', Fingerprinter::SEARCH_DIRS, true));

    $f = new Fingerprinter('', [$dir]);
    check('found in a well-known dir when not on PATH', $f->binaryPath() === $fake, var_export($f->binaryPath(), true));
    check('available', $f->isAvailable());
    $fp = $f->fingerprint('/any/file.mp3');
    check('fingerprint parsed', $fp->fingerprint === 'AQAAfake' && $fp->duration === 117);

    $f = new Fingerprinter('/no/such/fpcalc', [$dir]);
    check('invalid FPCALC_PATH falls back to the search', $f->binaryPath() === $fake, var_export($f->binaryPath(), true));

    $f = new Fingerprinter($fake, []);
    check('explicit FPCALC_PATH wins', $f->binaryPath() === $fake);

    putenv("PATH={$dir}");
    $f = new Fingerprinter('', []);
    check('found on PATH', $f->binaryPath() === $fake, var_export($f->binaryPath(), true));
    putenv('PATH=/nonexistent-dir');

    $f = new Fingerprinter('', []);
    check('nothing found → null', $f->binaryPath() === null);
    check('nothing found → unavailable', !$f->isAvailable());
    try {
        $f->fingerprint('/any/file.mp3');
        check('nothing found → fingerprint throws', false);
    } catch (EnrichmentException $e) {
        check('nothing found → fingerprint throws a helpful error', str_contains($e->getMessage(), 'brew install chromaprint'), $e->getMessage());
    }
} finally {
    putenv("PATH={$originalPath}");
    @unlink($fake);
    @rmdir($dir);
}

echo $failures === 0 ? "\nAll fingerprinter tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
