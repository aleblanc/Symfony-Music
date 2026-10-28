<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The latest YouTube videos of every library artist (app:artist:videos),
 * newest first; "new" ones are those since the last visit of the artist's
 * page, as on the artist cards. Viewing this list marks nothing as seen.
 */
class VideosController extends AbstractController
{
    private const LIMIT = 300;

    #[Route('/videos', name: 'app_videos', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user, ArtistProfileRepository $profiles, MusicRepository $music, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $onlyNew = $request->query->getBoolean('new');
        // Only the artists this viewer can see in the library.
        $visible = array_flip(array_map(static fn (array $a): string => (string) $a['artist'], $music->listArtists($user)));

        $videos = [];
        $newCount = 0;
        foreach ($profiles->findWithYoutube() as $profile) {
            if (!isset($visible[$profile->getName()])) {
                continue;
            }
            $new = array_flip(array_column($profile->newVideos($now), 'id'));
            $newCount += \count($new);
            foreach ($profile->getVideos() as $video) {
                if ($onlyNew && !isset($new[$video['id']])) {
                    continue;
                }
                $videos[] = $video + ['artist' => $profile->getName(), 'new' => isset($new[$video['id']])];
            }
        }
        usort($videos, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));

        return $this->render('videos/index.html.twig', [
            'videos' => \array_slice($videos, 0, self::LIMIT),
            'total' => \count($videos),
            'limit' => self::LIMIT,
            'newCount' => $newCount,
            'onlyNew' => $onlyNew,
        ]);
    }
}
