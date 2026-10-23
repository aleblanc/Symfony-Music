<?php

namespace App\Service\Spotify;

use App\Service\BinaryLocator;
use App\Service\Browser\ChromePipe;

/**
 * Spotify's own search page in the locally installed Google Chrome, run
 * headless and driven over the DevTools protocol (ChromePipe):
 * the last resort of app:spotify:match --browser. One browser for a batch.
 *
 * The page is rendered by Spotify's JavaScript, so a plain HTTP fetch sees
 * nothing; the browser runs it like any visitor. It is not disguised (its
 * user agent says HeadlessChrome) and searches are spaced out. Automated use
 * is against Spotify's terms and the markup can change: best effort.
 */
class HeadlessSearch
{
    /** Seconds to wait for result rows before giving up on a query. */
    private const ROWS_TIMEOUT = 12.0;

    /** Reads the first 10 rows of the track results (same fields as the old Playwright script). */
    private const JS_ROWS = <<<'JS'
        [...document.querySelectorAll('[data-testid="tracklist-row"]')].slice(0, 10).map((row) => {
            const link = row.querySelector('a[href*="/track/"]');
            const duration = [...row.querySelectorAll('div')].map((d) => d.textContent).find((t) => /^\d+(:\d\d){1,2}$/.test(t || '')) || null;
            return {
                id: ((link && link.getAttribute('href')) || '').match(/track\/([A-Za-z0-9]{22})/)?.[1] || null,
                name: link ? link.textContent : '',
                artists: [...row.querySelectorAll('a[href*="/artist/"]')].map((a) => a.textContent),
                duration: duration ? duration.split(':').reduce((total, part) => total * 60 + Number(part), 0) : null,
            };
        })
        JS;

    public function __construct(
        private readonly BinaryLocator $locator,
    ) {}

    /**
     * @param list<string>              $queries
     * @param ?callable(int $done):void $onResult called after each query
     *
     * @return list<list<SpotifyTrack>> results, in the order of $queries
     *
     * @throws SpotifyException when Chrome can't be found or started
     */
    public function search(array $queries, int $delayMs = 1500, ?callable $onResult = null): array
    {
        if ($queries === []) {
            return [];
        }

        $binary = $this->chromeBinary();
        $chrome = new ChromePipe();
        try {
            $chrome->start($binary);
            $chrome->openTab();
        } catch (\RuntimeException $e) {
            $chrome->stop();
            throw new SpotifyException('Headless Chrome could not start: ' . $e->getMessage());
        }

        $results = [];
        try {
            foreach ($queries as $i => $query) {
                if ($i > 0) {
                    usleep(max(0, $delayMs) * 1000);
                }
                $results[] = $this->searchOne($chrome, $query);
                if ($onResult !== null) {
                    $onResult(\count($results));
                }
            }
        } finally {
            $chrome->stop();
        }

        return $results;
    }

    /**
     * @return list<SpotifyTrack> empty when no row showed up in time (no result, or the page changed / blocked us)
     */
    private function searchOne(ChromePipe $chrome, string $query): array
    {
        try {
            $chrome->navigate('https://open.spotify.com/search/' . rawurlencode($query) . '/tracks');
            $deadline = microtime(true) + self::ROWS_TIMEOUT;
            do {
                $chrome->pump(0.5);
                $rows = $chrome->evaluate(self::JS_ROWS);
            } while ((!\is_array($rows) || $rows === []) && microtime(true) < $deadline);
        } catch (\RuntimeException) {
            return [];
        }

        return self::parse(\is_array($rows) ? $rows : []);
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<SpotifyTrack>
     */
    public static function parse(array $rows): array
    {
        $tracks = [];
        foreach ($rows as $t) {
            if (!\is_array($t) || !\is_string($t['id'] ?? null) || !preg_match('/^[A-Za-z0-9]{22}$/', $t['id'])) {
                continue;
            }
            $tracks[] = new SpotifyTrack(
                $t['id'],
                trim((string) ($t['name'] ?? '')),
                array_values(array_filter(\is_array($t['artists'] ?? null) ? $t['artists'] : [], 'is_string')),
                null,
                is_numeric($t['duration'] ?? null) ? (int) $t['duration'] : null,
            );
        }

        return $tracks;
    }

    /**
     * CHROME_PATH, else the macOS Google Chrome app, else google-chrome /
     * chromium found on the PATH.
     *
     * @throws SpotifyException
     */
    private function chromeBinary(): string
    {
        $configured = (string) getenv('CHROME_PATH');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        if (is_file(ChromePipe::MAC_CHROME)) {
            return ChromePipe::MAC_CHROME;
        }
        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $name) {
            if (($found = $this->locator->find($name)) !== null) {
                return $found;
            }
        }

        throw new SpotifyException('Google Chrome not found: install it, or set CHROME_PATH to the Chrome binary.');
    }
}
