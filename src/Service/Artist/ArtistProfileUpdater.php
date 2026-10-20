<?php

namespace App\Service\Artist;

use App\Entity\ArtistProfile;
use App\Repository\ArtistProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fills ArtistProfile rows: info (photo, Wikipedia, links) and latest
 * YouTube videos. Shared by `app:artist:fetch`, `app:artist:videos` and the
 * artist page's refresh button. The caller flushes.
 */
class ArtistProfileUpdater
{
    public function __construct(
        private readonly ArtistInfoFetcher $fetcher,
        private readonly YouTubeClient $youtube,
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

        return $profile;
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
