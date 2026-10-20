<?php

namespace App\Service;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds command-line tools (fpcalc...) the way a shell user would,
 * even when PHP runs under a web server whose PATH lacks Homebrew dirs:
 * explicit path first, then PATH, then well-known install dirs.
 */
class BinaryLocator
{
    public const SEARCH_DIRS = [
        '/opt/homebrew/bin',              // Homebrew, Apple Silicon
        '/usr/local/bin',                 // Homebrew Intel, manual installs
        '/opt/local/bin',                 // MacPorts
        '/home/linuxbrew/.linuxbrew/bin', // Linuxbrew
        '/usr/bin',
        '/bin',
        '/snap/bin',
    ];

    /**
     * @param string $explicit configured path (absolute, or a bare command name); '' to search
     * @param list<string> $searchDirs dirs tried after the PATH (~/.local/bin and ~/bin are added: pipx, pip --user)
     */
    public function find(string $name, string $explicit = '', array $searchDirs = self::SEARCH_DIRS): ?string
    {
        $explicit = trim($explicit);
        if ($explicit !== '' && is_file($explicit) && is_executable($explicit)) {
            return $explicit;
        }

        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
        if (\is_string($home) && $home !== '') {
            $searchDirs[] = $home . '/.local/bin';
            $searchDirs[] = $home . '/bin';
        }

        // An explicit value that is a bare name ("fpcalc2") is searched too.
        $command = $explicit !== '' && !str_contains($explicit, '/') ? $explicit : $name;

        return (new ExecutableFinder())->find($command, null, $searchDirs);
    }
}
