<?php

namespace App\Service\Lyrics;

use App\Service\Spotify\SpotifyMatcher;
use App\Service\Spotify\SpotifyTrack;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Synced (LRC) or plain lyrics from LRCLIB, a free crowd-sourced database
 * with no API key.
 *
 * Exact lookup first (/api/get: title + artist + album + duration, LRCLIB
 * allows ±2 s), then a search scored like the Spotify matching. A synced text
 * is only kept when the durations agree within {@see self::SYNC_TOLERANCE}
 * seconds: timestamps of another edit would scroll out of step.
 *
 * @see https://lrclib.net/docs
 */
class LrclibClient
{
    private const BASE_URL = 'https://lrclib.net/api/';
    private const SYNC_TOLERANCE = 3;
    private const ATTEMPTS = 3;
    private const UNKNOWN_ALBUMS = ['', 'unknown album', 'singles'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SpotifyMatcher $matcher,
        private readonly string $musicbrainzUserAgent,
        private readonly int $retryDelayMs = 2000,
    ) {}

    /**
     * @throws LyricsException when LRCLIB keeps failing (network, 5xx, 429)
     */
    public function find(string $title, string $artist, ?string $album, ?int $duration): ?LyricsMatch
    {
        [$cleanTitle, $artists] = $this->matcher->searchTerms($title, $artist);
        if ($cleanTitle === '') {
            return null;
        }
        $duration = $duration !== null && $duration > 0 ? $duration : null;

        $albumKnown = $album !== null && !\in_array(mb_strtolower(trim($album)), self::UNKNOWN_ALBUMS, true);
        if ($artists !== [] && $albumKnown && $duration !== null) {
            // Best effort: on a miss LRCLIB's /api/get asks external sources
            // and answers 503 when they fail, for that track every time. Only
            // the search below tells an outage.
            $record = $this->request('get', [
                'track_name' => $cleanTitle,
                'artist_name' => $artist,
                'album_name' => $album,
                'duration' => $duration,
            ], attempts: 1, fatal: false);
            if (\is_array($record) && ($match = $this->evaluate($record, $cleanTitle, $artists, $duration)) !== null) {
                return $match;
            }
        }

        $query = $artists !== []
            ? ['track_name' => $cleanTitle, 'artist_name' => $artists[0]]
            : ['q' => $cleanTitle];
        $best = null;
        foreach ($this->request('search', $query) ?? [] as $record) {
            if (!\is_array($record) || ($match = $this->evaluate($record, $cleanTitle, $artists, $duration)) === null) {
                continue;
            }
            // Higher score wins; at equal score a synced text beats a plain one.
            if ($best === null || $match->score > $best->score
                || ($match->score === $best->score && $match->synced !== null && $best->synced === null)) {
                $best = $match;
            }
        }

        return $best;
    }

    /**
     * @param array<mixed> $record
     * @param list<string> $artists
     */
    private function evaluate(array $record, string $title, array $artists, ?int $duration): ?LyricsMatch
    {
        $theirDuration = is_numeric($record['duration'] ?? null) ? (int) round((float) $record['duration']) : null;
        $candidate = new SpotifyTrack(
            id: (string) ($record['id'] ?? ''),
            name: \is_string($record['trackName'] ?? null) ? $record['trackName'] : '',
            artists: \is_string($record['artistName'] ?? null) ? [$record['artistName']] : [],
            album: \is_string($record['albumName'] ?? null) ? $record['albumName'] : null,
            durationSeconds: $theirDuration,
        );
        $score = $this->matcher->score($title, $artists, $duration, $candidate);
        if ($score === null || $score < SpotifyMatcher::MIN_SCORE) {
            return null;
        }

        $text = static fn(mixed $v): ?string => \is_string($v) && trim($v) !== '' ? trim($v) : null;
        $synced = $text($record['syncedLyrics'] ?? null);
        if ($synced !== null && ($duration === null || $theirDuration === null || abs($duration - $theirDuration) > self::SYNC_TOLERANCE)) {
            $synced = null;
        }
        $plain = $text($record['plainLyrics'] ?? null);
        $instrumental = (bool) ($record['instrumental'] ?? false);
        if ($synced === null && $plain === null && !$instrumental) {
            return null;
        }

        return new LyricsMatch(
            lrclibId: (int) ($record['id'] ?? 0),
            artist: $candidate->artists[0] ?? '',
            title: $candidate->name,
            synced: $synced,
            plain: $plain,
            instrumental: $instrumental,
            score: $score,
        );
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return ?array<mixed> decoded JSON, null on 404 / not JSON (or on any failure when not $fatal)
     *
     * @throws LyricsException when $fatal and LRCLIB keeps failing
     */
    private function request(string $endpoint, array $query, int $attempts = self::ATTEMPTS, bool $fatal = true): ?array
    {
        $lastError = '';
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            if ($attempt > 0 && $this->retryDelayMs > 0) {
                usleep($this->retryDelayMs * $attempt * 1000); // 2 s, then 4 s
            }
            try {
                $response = $this->httpClient->request('GET', self::BASE_URL . $endpoint, [
                    'query' => $query,
                    'headers' => ['User-Agent' => $this->musicbrainzUserAgent, 'Accept' => 'application/json'],
                    'timeout' => 15,
                    'max_duration' => 30,
                ]);
                $status = $response->getStatusCode();
                if ($status === 404) {
                    return null;
                }
                if ($status === 200) {
                    $data = json_decode($response->getContent(false), true);

                    return \is_array($data) ? $data : null;
                }
                $lastError = 'HTTP ' . $status;
                if ($status < 500 && $status !== 429) {
                    return null; // 400: bad query for this track, not an outage
                }
            } catch (HttpException $e) {
                $lastError = $e->getMessage();
            }
        }
        if (!$fatal) {
            return null;
        }

        throw new LyricsException('LRCLIB is not answering (' . $lastError . '). Run the command again later: tracks done so far are saved.');
    }
}
