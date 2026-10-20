<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * AcoustID lookup: Chromaprint fingerprint → MusicBrainz recordings.
 * AcoustID allows 3 requests per second.
 *
 * @see https://acoustid.org/webservice
 */
class AcoustIdClient
{
    private const URL = 'https://api.acoustid.org/v2/lookup';
    private const MIN_INTERVAL = 0.34;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        private readonly string $acoustidApiKey,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->acoustidApiKey) !== '';
    }

    /**
     * @return list<RecordingMatch> best first; release summaries carry ids and titles only
     */
    public function lookup(Fingerprint $fingerprint): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $this->throttle();
        // POST: fingerprints are several KB, too long for a query string.
        $response = $this->httpClient->request('POST', self::URL, [
            'body' => [
                'client' => $this->acoustidApiKey,
                'format' => 'json',
                'meta' => 'recordings releases',
                'duration' => (string) $fingerprint->duration,
                'fingerprint' => $fingerprint->fingerprint,
            ],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new EnrichmentException(\sprintf('AcoustID returned HTTP %d', $response->getStatusCode()));
        }
        $data = $response->toArray(false);
        if (($data['status'] ?? '') !== 'ok') {
            throw new EnrichmentException('AcoustID error: ' . ($data['error']['message'] ?? 'unknown'));
        }

        $matches = [];
        foreach ($data['results'] ?? [] as $result) {
            $score = (float) ($result['score'] ?? 0);
            foreach ($result['recordings'] ?? [] as $recording) {
                if (!isset($recording['id']) || isset($matches[$recording['id']])) {
                    continue;
                }
                $artist = implode(' & ', array_map(static fn(array $a): string => (string) ($a['name'] ?? ''), $recording['artists'] ?? []));
                $matches[$recording['id']] = new RecordingMatch(
                    id: (string) $recording['id'],
                    title: (string) ($recording['title'] ?? ''),
                    artist: $artist,
                    duration: isset($recording['duration']) ? (int) round((float) $recording['duration']) : null,
                    score: $score,
                    releases: array_values(array_map(static fn(array $r): ReleaseSummary => new ReleaseSummary(
                        id: (string) ($r['id'] ?? ''),
                        title: (string) ($r['title'] ?? ''),
                        artist: '',
                        date: null,
                        status: null,
                        primaryType: null,
                        secondaryTypes: [],
                        trackCount: (int) ($r['track_count'] ?? 0),
                    ), $recording['releases'] ?? [])),
                );
            }
        }

        $matches = array_values($matches);
        usort($matches, static fn(RecordingMatch $a, RecordingMatch $b): int => $b->score <=> $a->score);

        return $matches;
    }

    private function throttle(): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        $wait = $this->lastRequestAt + self::MIN_INTERVAL - $now;
        if ($wait > 0) {
            $this->clock->sleep($wait);
        }
        $this->lastRequestAt = (float) $this->clock->now()->format('U.u');
    }
}
