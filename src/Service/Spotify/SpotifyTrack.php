<?php

namespace App\Service\Spotify;

/**
 * The few fields of a Spotify track object that matching needs.
 */
final readonly class SpotifyTrack
{
    /**
     * @param list<string> $artists
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $artists,
        public ?string $album,
        public ?int $durationSeconds,
    ) {}

    /**
     * @param array<mixed> $item a track object from the Web API
     */
    public static function fromApi(array $item): self
    {
        $artists = [];
        foreach (\is_array($item['artists'] ?? null) ? $item['artists'] : [] as $artist) {
            if (\is_array($artist) && isset($artist['name']) && \is_string($artist['name'])) {
                $artists[] = $artist['name'];
            }
        }
        $album = $item['album']['name'] ?? null;
        $durationMs = $item['duration_ms'] ?? null;

        return new self(
            id: (string) $item['id'],
            name: (string) $item['name'],
            artists: $artists,
            album: \is_string($album) ? $album : null,
            durationSeconds: is_numeric($durationMs) ? (int) round($durationMs / 1000) : null,
        );
    }

    public function url(): string
    {
        return 'https://open.spotify.com/track/' . $this->id;
    }
}
