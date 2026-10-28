<?php

namespace App\Controller;

use App\Entity\Mix;
use App\Entity\MixSong;
use App\Entity\Music;
use App\Entity\User;
use App\Repository\AlbumFavoriteRepository;
use App\Repository\FavoriteRepository;
use App\Repository\ListenLaterRepository;
use App\Repository\MixRepository;
use App\Repository\MixSongRepository;
use App\Repository\OfflineSongRepository;
use App\Service\Artist\DiscographyView;
use App\Service\RecommendationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * "Discovery" surface: mixes, trending, recommendations, artist
 * suggestions, radio and queue hydration. Ported from the legacy
 * `get_mixes`/`mix`/`mix_songs`, `get_recommendations`,
 * `get_recommended_artists`, `get_explore`, `get_trending`,
 * `get_radio_tracks` and `get_queue_songs` actions.
 *
 * All the computation lives in {@see RecommendationService}; this
 * controller only wires requests to it and renders the result.
 */
class DiscoveryController extends AbstractController
{
    private const MIXES_PER_PAGE = 24;
    private const TRENDING_PER_PAGE = 60;

    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly FavoriteRepository $favorites,
        private readonly ListenLaterRepository $listenLater,
        private readonly OfflineSongRepository $offline,
        private readonly MixRepository $mixes,
        private readonly MixSongRepository $mixSongs,
    ) {}

    /**
     * Discovery hub: a taste of trending tracks, the "Explore" shelves and a
     * teaser of mixes, with a link through to the full "For You" page when
     * logged in.
     */
    #[Route('/discover', name: 'app_discover')]
    public function hub(#[CurrentUser] ?User $user, DiscographyView $discography, AlbumFavoriteRepository $albumFavorites): Response
    {
        $this->recommendations->refreshMixes();
        $shelves = $this->recommendations->explore($user);
        $toDiscover = $discography->albumsToDiscover($user, $albumFavorites->releaseGroupIdsForUser($user));
        if ($toDiscover !== []) {
            // Right after the random "Discover…" shelves, before "Frequently Played".
            $at = \count(array_filter($shelves, static fn(array $s): bool => str_starts_with($s['key'], 'discover_')));
            array_splice($shelves, $at, 0, [['key' => 'albums_from_your_artists', 'title' => new TranslatableMessage('ui.discover.shelf.albums_from_your_artists'), 'type' => 'discography', 'items' => $toDiscover, 'more' => $this->generateUrl('app_discover_albums')]]);
        }

        return $this->render('discovery/hub.html.twig', [
            'trending' => $this->recommendations->trending($user, 12),
            'shelves' => $shelves,
            'mixes' => iterator_to_array($this->mixes->paginate(null, MixRepository::DEFAULT_SORT, 1, 6)),
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * Every album to discover from your artists (the hub shows the first 12).
     */
    #[Route('/discover/albums', name: 'app_discover_albums')]
    public function albumsToDiscover(#[CurrentUser] ?User $user, DiscographyView $discography, AlbumFavoriteRepository $albumFavorites): Response
    {
        return $this->render('discovery/albums.html.twig', [
            'cards' => $discography->albumsToDiscover($user, $albumFavorites->releaseGroupIdsForUser($user), limit: 120),
        ]);
    }

    /**
     * Full trending list (all users' combined play counts), ported from
     * `get_trending`.
     */
    #[Route('/discover/trending', name: 'app_discover_trending')]
    public function trending(Request $request, #[CurrentUser] ?User $user): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = self::TRENDING_PER_PAGE;

        return $this->render('discovery/trending.html.twig', [
            'songs' => $this->recommendations->trending($user, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * Personalised "For You" shelves, ported from `get_recommendations`.
     * Requires login (the legacy action returned an empty shelf list for
     * guests; we redirect to login instead, consistent with the other
     * per-user pages in this app).
     */
    #[Route('/discover/for-you', name: 'app_discover_recommendations')]
    #[IsGranted('ROLE_USER')]
    public function forYou(#[CurrentUser] User $user): Response
    {
        return $this->render('discovery/recommendations.html.twig', [
            'shelves' => $this->recommendations->recommendations($user),
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * Browsable, searchable list of algorithmically generated mixes, ported
     * from `get_mixes`. Auto-seeds/expires on load, mirroring the legacy
     * inline housekeeping.
     */
    #[Route('/discover/mixes', name: 'app_discover_mixes')]
    public function mixes(Request $request): Response
    {
        $this->recommendations->refreshMixes();

        $page = max(1, $request->query->getInt('page', 1));
        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', MixRepository::DEFAULT_SORT);
        if (!\in_array($sort, MixRepository::SORT_KEYS, true)) {
            $sort = MixRepository::DEFAULT_SORT;
        }

        $paginator = $this->mixes->paginate($q ?: null, $sort, $page, self::MIXES_PER_PAGE);
        $total = \count($paginator);
        $items = iterator_to_array($paginator);

        return $this->render('discovery/mixes.html.twig', [
            'mixes' => $items,
            'songCounts' => array_combine(
                array_map(static fn(Mix $m): int => $m->getId() ?? throw new \LogicException('Persisted mix must have an id.'), $items),
                array_map(fn(Mix $m): int => $this->mixes->songCount($m), $items),
            ),
            'query' => $q,
            'sort' => $sort,
            'page' => $page,
            'pages' => (int) ceil($total / self::MIXES_PER_PAGE),
            'total' => $total,
        ]);
    }

    /**
     * A single mix's track list, ported from the legacy `mix` (OG share
     * metadata) + `mix_songs` (actual track ids) actions. Visibility is
     * enforced here (unlike mix *generation*, see {@see MixRepository}
     * docblock) so a private track only shows up in a mix for its owner or
     * a super admin.
     */
    #[Route('/mix/{publicId}', name: 'app_mix_show')]
    public function mixShow(string $publicId, #[CurrentUser] ?User $user): Response
    {
        $mix = $this->mixes->findOneByPublicId($publicId);
        if ($mix === null) {
            throw $this->createNotFoundException('Mix not found.');
        }

        $songs = array_values(array_filter(
            array_map(static fn(MixSong $ms) => $ms->getSong(), $this->mixSongs->findForMix($mix)),
            fn($song) => $song !== null && $this->recommendations->isVisibleTo($song, $user),
        ));

        return $this->render('discovery/mix_show.html.twig', [
            'mix' => $mix,
            'songs' => $songs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * Radio: a random shuffle of tracks sharing the seed track's genre or
     * artist. Ported from `get_radio_tracks`. JSON, consumed by the player
     * for an "auto continue" queue.
     */
    #[Route('/discover/radio/{id}', name: 'app_discover_radio', requirements: ['id' => '\d+'])]
    public function radio(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $songs = $this->recommendations->radioTracks($user, $id);

        return new JsonResponse(array_map($this->songPayload(...), $songs));
    }

    /**
     * Hydrates a client-held queue (array of song ids in the player) back
     * into track metadata, preserving order. Ported from `get_queue_songs`.
     * Read-only (no data is modified), so — like the legacy action — this
     * does not require a CSRF token; it is a POST only because the id list
     * can be long.
     */
    #[Route('/discover/queue', name: 'app_discover_queue', methods: ['POST'])]
    public function queue(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = \is_array($data) && \is_array($data['ids'] ?? null) ? array_map('intval', $data['ids']) : [];

        if ($ids === []) {
            return new JsonResponse([]);
        }

        $songs = $this->recommendations->queueSongs($ids);

        return new JsonResponse(array_map($this->songPayload(...), $songs));
    }

    /**
     * Random registered users to suggest, ported from
     * `get_recommended_artists`. JSON.
     */
    #[Route('/discover/recommended-artists', name: 'app_discover_recommended_artists')]
    public function recommendedArtists(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $targetId = $request->query->getInt('target_id') ?: null;
        $users = $this->recommendations->recommendedArtists($user, $targetId);

        return new JsonResponse(array_map(
            static fn(User $u): array => ['id' => $u->getId(), 'artist' => $u->getArtist()],
            $users,
        ));
    }

    /**
     * @return array{id: int, title: string, artist: ?string, album: ?string, duration: ?int}
     */
    private function songPayload(Music $song): array
    {
        return [
            'id' => $song->getId() ?? throw new \LogicException('Persisted song must have an id.'),
            'title' => $song->getDisplayTitle(),
            'artist' => $song->getArtist(),
            'album' => $song->getAlbum(),
            'duration' => $song->getDuration(),
        ];
    }

    /**
     * @return int[]
     */
    private function favoriteIds(?User $user): array
    {
        return $user === null ? [] : $this->favorites->favoritedSongIds($user);
    }

    /**
     * @return int[]
     */
    private function listenLaterIds(?User $user): array
    {
        return $user === null ? [] : $this->listenLater->songIdsForUser($user);
    }

    /**
     * @return int[]
     */
    private function offlineIds(?User $user): array
    {
        return $user === null ? [] : $this->offline->songIdsForUser($user);
    }
}
