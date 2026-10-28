<?php

namespace App\Service\Artist;

use App\Entity\ArtistProfile;
use App\Repository\ArtistProfileRepository;
use App\Service\Enrichment\EnrichmentException;
use App\Service\Enrichment\MusicBrainzClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fills ArtistProfile rows: info (photo, Wikipedia, links), discography
 * (MusicBrainz studio albums and EPs with their track list, ListenBrainz popularity)
 * and latest YouTube videos. Shared by `app:cron:artist-info`, `app:cron:artist-videos` and the
 * artist page's refresh button. The caller flushes.
 */
class ArtistProfileUpdater
{
    public function __construct(
        private readonly ArtistInfoFetcher $fetcher,
        private readonly YouTubeClient $youtube,
        private readonly MusicBrainzClient $musicBrainz,
        private readonly ListenBrainzClient $listenBrainz,
        private readonly ArtistProfileRepository $profiles,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param list<string> $albums album titles of the artist in the library
     */
    public function refreshInfo(string $name, array $albums): ArtistProfile
    {
        $profile = $this->profile($name);
        $info = $this->fetcher->fetch($name, $albums);
        $now = $this->clock->now();
        if ($info === null) {
            $profile->markNotFound($now);

            return $profile;
        }
        $profile->markFound($info['mbid'], $info['wikidataId'], $info['description'], $info['imageUrl'], $info['wikipediaUrl'], $info['summary'], $info['youtubeChannelId'], $info['youtubeUrl'], $info['links'], $now);
        $this->refreshDiscography($profile);

        return $profile;
    }

    /** False when MusicBrainz could not be read (the previous list is kept). */
    public function refreshDiscography(ArtistProfile $profile): bool
    {
        if ($profile->getMbid() === null) {
            return false;
        }
        try {
            $albums = $this->musicBrainz->getArtistAlbums($profile->getMbid());
        } catch (EnrichmentException) {
            return false;
        }
        // Listens / listeners per album; null when ListenBrainz is down (shown as unknown, refetched next time).
        $popularity = $this->listenBrainz->releaseGroupPopularity(array_column($albums, 'id'));
        // Track list of each album's original edition: false when it has no official release,
        // null when MusicBrainz failed (refetched next time).
        $previous = array_column($profile->getDiscography() ?? [], 'tracklist', 'id');
        foreach ($albums as &$album) {
            $album['listens'] = $popularity === null ? null : ($popularity[$album['id']]['listens'] ?? 0);
            $album['listeners'] = $popularity === null ? null : ($popularity[$album['id']]['listeners'] ?? 0);
            try {
                $album['tracklist'] = $this->musicBrainz->getReleaseGroupTracks($album['id']) ?? false;
            } catch (EnrichmentException) {
                $album['tracklist'] = $previous[$album['id']] ?? null;
            }
        }
        unset($album);

        // Listeners of each track (its recording, whatever release it was played from): the album's standout titles.
        $recordings = [];
        foreach ($albums as $album) {
            foreach (\is_array($album['tracklist'] ?? null) ? $album['tracklist']['tracks'] : [] as $t) {
                if (($t['recording'] ?? null) !== null) {
                    $recordings[] = $t['recording'];
                }
            }
        }
        $trackPopularity = $this->listenBrainz->recordingPopularity($recordings);
        foreach ($albums as &$album) {
            if (!\is_array($album['tracklist'] ?? null)) {
                continue;
            }
            foreach ($album['tracklist']['tracks'] as &$t) {
                $t['listeners'] = $trackPopularity === null || ($t['recording'] ?? null) === null ? null : ($trackPopularity[$t['recording']]['listeners'] ?? 0);
            }
            unset($t);
        }
        unset($album);
        $profile->setDiscography($albums);

        return true;
    }

    /**
     * @return ?int number of new videos (published since the last page visit), null when the feed could not be read
     */
    public function refreshVideos(ArtistProfile $profile): ?int
    {
        $channel = $profile->getYoutubeChannelId();
        if ($channel === null) {
            return 0;
        }
        $videos = $this->youtube->latestVideos($channel);
        if ($videos === null) {
            return null;
        }
        $profile->setVideos($videos, $this->clock->now());

        return \count($profile->newVideos($this->clock->now()));
    }

    private function profile(string $name): ArtistProfile
    {
        $profile = $this->profiles->findOneByName($name);
        if ($profile === null) {
            $profile = new ArtistProfile($name);
            $this->em->persist($profile);
        }

        return $profile;
    }
}
