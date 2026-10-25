<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\User;
use App\Repository\MusicRepository;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;

/**
 * Features this server does not have (shares, internet radio, podcasts,
 * "now playing" of other users, server-side scan trigger), answered with
 * empty but valid responses so clients such as Tempo show empty screens
 * instead of errors.
 */
final class StubHandler implements SubsonicHandler
{
    public function __construct(private readonly MusicRepository $music) {}

    public static function methods(): array
    {
        return ['getShares', 'getInternetRadioStations', 'getPodcasts', 'getNewestPodcasts', 'getNowPlaying', 'startScan',
            'getBookmarks', 'getVideos'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        return match ($method) {
            'getShares' => ['shares' => new \stdClass()],
            'getInternetRadioStations' => ['internetRadioStations' => new \stdClass()],
            'getPodcasts' => ['podcasts' => new \stdClass()],
            'getNewestPodcasts' => ['newestPodcasts' => new \stdClass()],
            'getNowPlaying' => ['nowPlaying' => new \stdClass()],
            'getBookmarks' => ['bookmarks' => new \stdClass()],
            'getVideos' => ['videos' => new \stdClass()],
            // Scans stay a manual `app:scan`; report the current state.
            'startScan' => ['scanStatus' => ['scanning' => false, 'count' => $this->music->countAll($user)]],
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
    }
}
