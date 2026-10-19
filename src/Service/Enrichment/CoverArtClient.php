<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Front covers from the Cover Art Archive (500 px thumbnails: plenty for the
 * UI and keeps embedded art small). Falls back to the release group's cover
 * when this exact edition has none.
 *
 * @see https://musicbrainz.org/doc/Cover_Art_Archive/API
 */
class CoverArtClient
{
    private const BASE_URL = 'https://coverartarchive.org/';
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /** @return ?string image bytes */
    public function front(string $releaseId, ?string $releaseGroupId = null): ?string
    {
        return $this->download('release/' . rawurlencode($releaseId) . '/front-500')
            ?? ($releaseGroupId !== null ? $this->download('release-group/' . rawurlencode($releaseGroupId) . '/front-500') : null);
    }

    private function download(string $path): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $path, [
                'headers' => ['User-Agent' => $this->musicbrainzUserAgent],
                'max_redirects' => 5,
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $type = $response->getHeaders(false)['content-type'][0] ?? '';
            $body = $response->getContent(false);
        } catch (HttpException) {
            return null;
        }

        if (!str_starts_with($type, 'image/') || $body === '' || \strlen($body) > self::MAX_BYTES) {
            return null;
        }

        return $body;
    }
}
