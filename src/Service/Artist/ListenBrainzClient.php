<?php

namespace App\Service\Artist;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Popularity from ListenBrainz (MusicBrainz's open listening data): total
 * listens and distinct listeners per release group (every album of an
 * artist in one request) and per recording (a track list's titles), no API key. Its rate limit (about 30 requests per
 * few seconds, given in X-RateLimit-* headers) is respected: a pause when
 * the quota is spent, a wait-and-retry on HTTP 429.
 *
 * @see https://listenbrainz.readthedocs.io/en/latest/users/api/popularity.html
 */
class ListenBrainzClient
{
    private const URL = 'https://api.listenbrainz.org/1/popularity/';
    /** Ids per request (the API takes lists; kept moderate). */
    private const BATCH = 100;
    private const MAX_ATTEMPTS = 4;

    /** Unix time before which no request may be sent (quota spent). */
    private float $pausedUntil = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /**
     * @param list<string> $releaseGroupIds MusicBrainz release group ids
     *
     * @return ?array<string, array{listens: int, listeners: int}> by id (unknown ones left out), null when ListenBrainz could not be read
     */
    public function releaseGroupPopularity(array $releaseGroupIds): ?array
    {
        return $this->popularity('release-group', $releaseGroupIds);
    }

    /**
     * @param list<string> $recordingIds MusicBrainz recording ids
     *
     * @return ?array<string, array{listens: int, listeners: int}> by id (unknown ones left out), null when ListenBrainz could not be read
     */
    public function recordingPopularity(array $recordingIds): ?array
    {
        return $this->popularity('recording', $recordingIds);
    }

    /**
     * @param list<string> $ids
     *
     * @return ?array<string, array{listens: int, listeners: int}>
     */
    private function popularity(string $entity, array $ids): ?array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::BATCH) as $chunk) {
            $part = $this->popularityBatch($entity, $chunk);
            if ($part === null) {
                return null;
            }
            $out += $part;
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return ?array<string, array{listens: int, listeners: int}>
     */
    private function popularityBatch(string $entity, array $ids): ?array
    {
        $rows = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $rows === null; ++$attempt) {
            $this->waitForQuota();
            try {
                $response = $this->httpClient->request('POST', self::URL . $entity, [
                    'json' => [str_replace('-', '_', $entity) . '_mbids' => $ids],
                    'headers' => ['User-Agent' => $this->musicbrainzUserAgent],
                    'timeout' => 20,
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $resetIn = (float) ($headers['x-ratelimit-reset-in'][0] ?? 0);
                if ((int) ($headers['x-ratelimit-remaining'][0] ?? 1) <= 0 || $status === 429) {
                    $this->pausedUntil = microtime(true) + max(1.0, $resetIn + 0.5);
                }
                if ($status === 429 || $status >= 500) {
                    continue;
                }
                $rows = $response->toArray();
            } catch (ExceptionInterface) {
                $this->pausedUntil = microtime(true) + 2.0 * $attempt;
            }
        }
        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $id = \is_array($row) ? ($row[str_replace('-', '_', $entity) . '_mbid'] ?? null) : null;
            if ($id !== null && ($row['total_listen_count'] ?? null) !== null) {
                $out[(string) $id] = [
                    'listens' => (int) $row['total_listen_count'],
                    'listeners' => (int) ($row['total_user_count'] ?? 0),
                ];
            }
        }

        return $out;
    }

    private function waitForQuota(): void
    {
        $wait = $this->pausedUntil - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
    }
}
