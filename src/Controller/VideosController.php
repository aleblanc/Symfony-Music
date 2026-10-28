<?php

namespace App\Controller;

use App\Entity\ArtistProfile;
use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use App\Service\Artist\InstagramClient;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The latest YouTube videos (app:artist:videos) and Instagram posts
 * (app:artist:posts) of every library artist, newest first; "new" ones are
 * those since the last visit of the artist's page, as on the artist cards.
 * Viewing these lists marks nothing as seen.
 */
class VideosController extends AbstractController
{
    private const LIMIT = 300;

    #[Route('/videos', name: 'app_videos', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user, ArtistProfileRepository $profiles, MusicRepository $music, ClockInterface $clock): Response
    {
        return $this->render('videos/index.html.twig', $this->feed($request, $user, $music, $clock, $profiles->findWithYoutube(),
            static fn (ArtistProfile $p): array => $p->getVideos(), static fn (ArtistProfile $p, \DateTimeImmutable $now): array => $p->newVideos($now)));
    }

    #[Route('/posts', name: 'app_posts', methods: ['GET'])]
    public function posts(Request $request, #[CurrentUser] ?User $user, ArtistProfileRepository $profiles, MusicRepository $music, ClockInterface $clock): Response
    {
        return $this->render('videos/posts.html.twig', $this->feed($request, $user, $music, $clock, $profiles->findAll(),
            static fn (ArtistProfile $p): array => $p->getPosts(), static fn (ArtistProfile $p, \DateTimeImmutable $now): array => $p->newPosts($now)));
    }

    /**
     * A post's thumbnail, served by the app: Instagram's CDN refuses to be
     * shown on another site. Only for posts the profiles hold (no open proxy).
     */
    #[Route('/instagram/thumb/{code}.jpg', name: 'app_instagram_thumb', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9_-]{6,40}'])]
    public function instagramThumb(string $code, ArtistProfileRepository $profiles, InstagramClient $instagram): Response
    {
        if (!$profiles->hasPost($code)) {
            throw $this->createNotFoundException();
        }
        $file = $instagram->thumbnail($code) ?? throw $this->createNotFoundException('Thumbnail unavailable.');
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'image/jpeg']);
        $response->setPublic();
        $response->setMaxAge(30 * 86400);

        return $response;
    }

    /**
     * Every item of the profiles of the artists this viewer can see, newest first.
     *
     * @param list<ArtistProfile> $profiles
     * @param \Closure(ArtistProfile): list<array{id: string, published: string}> $items
     * @param \Closure(ArtistProfile, \DateTimeImmutable): list<array{id: string}> $newItems
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, newCount: int, onlyNew: bool}
     */
    private function feed(Request $request, ?User $user, MusicRepository $music, ClockInterface $clock, array $profiles, \Closure $items, \Closure $newItems): array
    {
        $now = $clock->now();
        $onlyNew = $request->query->getBoolean('new');
        // Only the artists this viewer can see in the library.
        $visible = array_flip(array_map(static fn (array $a): string => (string) $a['artist'], $music->listArtists($user)));

        $list = [];
        $newCount = 0;
        foreach ($profiles as $profile) {
            if (!isset($visible[$profile->getName()])) {
                continue;
            }
            $new = array_flip(array_column($newItems($profile, $now), 'id'));
            $newCount += \count($new);
            foreach ($items($profile) as $item) {
                if ($onlyNew && !isset($new[$item['id']])) {
                    continue;
                }
                $list[] = $item + ['artist' => $profile->getName(), 'new' => isset($new[$item['id']])];
            }
        }
        usort($list, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));

        return [
            'items' => \array_slice($list, 0, self::LIMIT),
            'total' => \count($list),
            'limit' => self::LIMIT,
            'newCount' => $newCount,
            'onlyNew' => $onlyNew,
        ];
    }
}
