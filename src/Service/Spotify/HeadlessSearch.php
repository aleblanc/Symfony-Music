<?php

namespace App\Service\Spotify;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Runs bin/spotify-search.mjs (Spotify's own search page in a headless
 * Chromium) for a batch of queries: one browser for the whole batch.
 */
class HeadlessSearch
{
    public function __construct(private readonly string $projectDir) {}

    /**
     * @param list<string>              $queries
     * @param ?callable(int $done):void $onResult called after each query
     *
     * @return list<list<SpotifyTrack>> results, in the order of $queries
     *
     * @throws SpotifyException when the browser can't run (Node/Playwright missing…)
     */
    public function search(array $queries, int $delayMs = 1500, ?callable $onResult = null): array
    {
        if ($queries === []) {
            return [];
        }

        $process = new Process(['node', $this->projectDir . '/bin/spotify-search.mjs'], $this->projectDir, [
            'SEARCH_DELAY_MS' => (string) max(0, $delayMs),
        ], json_encode($queries, \JSON_THROW_ON_ERROR), null);

        $results = [];
        $buffer = '';
        try {
            $process->mustRun(function (string $type, string $data) use (&$results, &$buffer, $onResult): void {
                if ($type !== Process::OUT) {
                    return;
                }
                $buffer .= $data;
                while (($eol = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $eol);
                    $buffer = substr($buffer, $eol + 1);
                    $results[] = $this->parse($line);
                    if ($onResult !== null) {
                        $onResult(\count($results));
                    }
                }
            });
        } catch (ProcessFailedException) {
            throw new SpotifyException('Headless search failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            throw new SpotifyException('Headless search could not start (is Node.js installed?): ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * @return list<SpotifyTrack>
     */
    private function parse(string $line): array
    {
        $data = json_decode($line, true);
        $tracks = [];
        foreach (\is_array($data) && \is_array($data['tracks'] ?? null) ? $data['tracks'] : [] as $t) {
            if (!\is_array($t) || !\is_string($t['id'] ?? null) || !preg_match('/^[A-Za-z0-9]{22}$/', $t['id'])) {
                continue;
            }
            $tracks[] = new SpotifyTrack(
                $t['id'],
                (string) ($t['name'] ?? ''),
                array_values(array_filter(\is_array($t['artists'] ?? null) ? $t['artists'] : [], 'is_string')),
                null,
                is_numeric($t['duration'] ?? null) ? (int) $t['duration'] : null,
            );
        }

        return $tracks;
    }
}
