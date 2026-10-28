<?php

namespace App\Service\Artist;

use App\Service\Browser\ChromePipe;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Instagram without an API key: the public profile page, opened in Chrome as
 * any logged-out visitor sees it (the HTML alone is an empty JavaScript
 * shell). Reads the posts of the grid (about 12): shortcode, post or reel,
 * the thumbnail's alt text. The date comes from the shortcode itself (the
 * media id holds its creation time), so no post page is opened. When
 * Instagram shows its login wall instead, LoginWallException: the caller
 * stops for this run, nothing tries to get around it.
 *
 * Thumbnails: Instagram's CDN refuses to be shown on another site
 * (Cross-Origin-Resource-Policy: same-origin), so they are fetched here once
 * and served by the app from var/instagram (pruned with the posts).
 */
final class InstagramClient
{
    public const THUMB_DIR = 'var/instagram';

    /** Instagram ids: milliseconds since this epoch (Aug 2011), shifted 23 bits left. */
    private const EPOCH_MS = 1314220021721;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    /** Seconds the grid gets to show up. */
    private const LOAD_TIMEOUT = 20;

    private const JS_GRID = <<<'JS'
        (() => {
            const login = location.pathname.startsWith('/accounts/login') || !!document.querySelector('input[name="password"]');
            const missing = /isn.t available|n.est pas disponible|Page not found/i.test(document.title + ' ' + (document.querySelector('main')?.innerText || '').slice(0, 300));
            const posts = [];
            const seen = new Set();
            for (const a of document.querySelectorAll('main a[href*="/p/"], main a[href*="/reel/"]')) {
                const m = (a.getAttribute('href') || '').match(/\/(p|reel)\/([A-Za-z0-9_-]{6,40})/);
                if (!m || seen.has(m[2])) continue;
                seen.add(m[2]);
                const img = a.querySelector('img');
                posts.push({type: m[1], id: m[2], caption: img ? (img.getAttribute('alt') || null) : null});
            }
            return {login, missing, posts};
        })()
        JS;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {}

    /** Local copy of a post's thumbnail (about 320 px), fetched on first use; null when Instagram does not give it. */
    public function thumbnail(string $shortcode): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{6,40}$/', $shortcode) !== 1) {
            return null;
        }
        $file = $this->thumbDir() . '/' . $shortcode . '.jpg';
        if (is_file($file)) {
            return $file;
        }
        try {
            // A redirect to a fresh, signed CDN link of the image.
            $response = $this->httpClient->request('GET', 'https://www.instagram.com/p/' . $shortcode . '/media/?size=m', [
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; PHPMusic/1.0)'],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            if ($response->getStatusCode() !== 200 || !str_starts_with($response->getHeaders()['content-type'][0] ?? '', 'image/')) {
                return null;
            }
            $bytes = $response->getContent();
        } catch (ExceptionInterface) {
            return null;
        }
        if (!is_dir(\dirname($file)) && !mkdir(\dirname($file), 0o777, true) && !is_dir(\dirname($file))) {
            return null;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $file)) {
            @unlink($tmp);

            return null;
        }

        return $file;
    }

    /**
     * Deletes the thumbnails of the posts no longer kept.
     *
     * @param list<string> $keep shortcodes still in a profile
     *
     * @return int files deleted
     */
    public function pruneThumbnails(array $keep): int
    {
        $keep = array_flip($keep);
        $deleted = 0;
        foreach (glob($this->thumbDir() . '/*.jpg') ?: [] as $file) {
            if (!isset($keep[basename($file, '.jpg')]) && @unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function thumbDir(): string
    {
        return rtrim($this->projectDir, '/') . '/' . self::THUMB_DIR;
    }

    /**
     * @return list<array{id: string, type: string, caption: ?string, published: string}> newest first
     *
     * @throws LoginWallException when Instagram asks to log in
     * @throws \RuntimeException when the page shows no post (account gone, page changed)
     */
    public function latestPosts(ChromePipe $chrome, string $handle): array
    {
        $chrome->navigate('https://www.instagram.com/' . rawurlencode($handle) . '/');
        $state = ['login' => false, 'missing' => false, 'posts' => []];
        $deadline = microtime(true) + self::LOAD_TIMEOUT;
        while (microtime(true) < $deadline) {
            $chrome->pump(1.0);
            $state = (array) $chrome->evaluate(self::JS_GRID);
            // The grid fills in batches: one more second once posts show, so they are all there.
            if ($state['posts'] !== [] || $state['missing']) {
                $chrome->pump(1.5);
                $state = (array) $chrome->evaluate(self::JS_GRID);
                break;
            }
            if ($state['login']) {
                break;
            }
        }
        if ($state['posts'] === [] && $state['login']) {
            throw new LoginWallException('Instagram asks to log in');
        }
        if ($state['posts'] === []) {
            throw new \RuntimeException($state['missing'] ? 'account not found' : 'no post on the page');
        }

        $posts = [];
        foreach ($state['posts'] as $post) {
            $published = self::publishedAt((string) $post['id']);
            if ($published === null) {
                continue;
            }
            $caption = \is_string($post['caption'] ?? null) ? trim($post['caption']) : '';
            $posts[] = [
                'id' => (string) $post['id'],
                'type' => $post['type'] === 'reel' ? 'reel' : 'p',
                'caption' => $caption !== '' ? mb_substr($caption, 0, 500) : null,
                'published' => $published->format(\DATE_ATOM),
            ];
        }
        usort($posts, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));

        return $posts;
    }

    /** Creation time held by a post's shortcode (null for the long ids of private posts). */
    public static function publishedAt(string $shortcode): ?\DateTimeImmutable
    {
        if (\strlen($shortcode) > 11 || preg_match('/^[A-Za-z0-9_-]+$/', $shortcode) !== 1) {
            return null;
        }
        $id = 0;
        foreach (str_split($shortcode) as $char) {
            $id = $id * 64 + strpos(self::ALPHABET, $char);
        }
        if (!\is_int($id) || $id <= 0) {
            return null;
        }
        $ms = ($id >> 23) + self::EPOCH_MS;

        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }
}
