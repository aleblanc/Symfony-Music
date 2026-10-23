<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AlbumFavoriteRepository;
use App\Repository\ArtistProfileRepository;
use App\Repository\FavoriteRepository;
use App\Repository\HistoryRepository;
use App\Repository\ListenLaterRepository;
use App\Repository\MusicRepository;
use App\Repository\OfflineSongRepository;
use App\Repository\PlayCountRepository;
use App\Service\Artist\DiscographyView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class LibraryController extends AbstractController
{
    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly ListenLaterRepository $listenLater,
        private readonly OfflineSongRepository $offline,
    ) {}

    #[Route('/', name: 'app_home')]
    public function home(
        #[CurrentUser]
        ?User $user,
        MusicRepository $music,
        HistoryRepository $history,
        PlayCountRepository $playCounts,
    ): Response {
        $recent = $user ? $history->recentSongs($user, 12) : [];
        $topSongs = $user ? $playCounts->topSongs($user, 12) : [];

        return $this->render('library/home.html.twig', [
            'total' => $music->countAll($user),
            'recentlyAdded' => $music->findRecentlyAdded($user, 12),
            'recent' => $recent,
            'topSongs' => $topSongs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    #[Route('/library', name: 'app_library')]
    public function library(
        Request $request,
        #[CurrentUser]
        ?User $user,
        MusicRepository $music,
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $query = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', MusicRepository::DEFAULT_SORT);
        if (!\in_array($sort, MusicRepository::SORT_KEYS, true)) {
            $sort = MusicRepository::DEFAULT_SORT;
        }
        $perPage = 60;

        $paginator = $music->paginateLibrary($user, $query ?: null, $page, $perPage, $sort);
        $total = \count($paginator);

        return $this->render('library/list.html.twig', [
            'songs' => iterator_to_array($paginator),
            'query' => $query,
            'sort' => $sort,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    #[Route('/genres', name: 'app_genres')]
    public function genres(#[CurrentUser] ?User $user, MusicRepository $music): Response
    {
        return $this->render('library/genres.html.twig', [
            'genres' => $music->listGenres($user),
            'covers' => $music->albumCoverIds('genre', $user),
        ]);
    }

    #[Route('/genre/{name}', name: 'app_genre', requirements: ['name' => '.+'])]
    public function genre(string $name, #[CurrentUser] ?User $user, MusicRepository $music): Response
    {
        $songs = $music->findByGenre($name, $user);
        if ($songs === []) {
            throw $this->createNotFoundException('Genre not found or has no visible tracks.');
        }

        return $this->render('library/genre.html.twig', [
            'genre' => $name,
            'songs' => $songs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    #[Route('/years', name: 'app_years')]
    public function years(#[CurrentUser] ?User $user, MusicRepository $music): Response
    {
        return $this->render('library/years.html.twig', [
            'years' => $music->listYears($user),
            'covers' => $music->albumCoverIds('year', $user),
        ]);
    }

    #[Route('/year/{year}', name: 'app_year', requirements: ['year' => '\d+'])]
    public function year(int $year, #[CurrentUser] ?User $user, MusicRepository $music): Response
    {
        $songs = $music->findByYear($year, $user);
        if ($songs === []) {
            throw $this->createNotFoundException('Year not found or has no visible tracks.');
        }

        return $this->render('library/year.html.twig', [
            'year' => $year,
            'songs' => $songs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    #[Route('/artists', name: 'app_artists')]
    public function artists(#[CurrentUser] ?User $user, MusicRepository $music, ArtistProfileRepository $profiles, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $newVideos = [];
        foreach ($profiles->findWithYoutube() as $profile) {
            if (($n = \count($profile->newVideos($now))) > 0) {
                $newVideos[$profile->getName()] = $n;
            }
        }

        return $this->render('library/artists.html.twig', [
            'artists' => $music->listArtists($user),
            'covers' => $music->albumCoverIds('artist', $user),
            'newVideos' => $newVideos,
        ]);
    }

    #[Route('/artist/{name}', name: 'app_artist', requirements: ['name' => '.+'])]
    public function artist(string $name, #[CurrentUser] ?User $user, MusicRepository $music, ArtistProfileRepository $profiles, EntityManagerInterface $em, ClockInterface $clock, DiscographyView $discographyView, AlbumFavoriteRepository $albumFavorites): Response
    {
        $songs = $music->findByArtist($name, $user);
        if ($songs === []) {
            throw $this->createNotFoundException('Artist not found or has no visible tracks.');
        }

        // Videos are "new" until this visit: flagged now, not on the next one.
        $profile = $profiles->findOneByName($name);
        $newVideoIds = [];
        if ($profile !== null) {
            $newVideoIds = array_column($profile->newVideos($clock->now()), 'id');
            if ($profile->getVideos() !== []) {
                $profile->markVideosSeen($clock->now());
                $em->flush();
            }
        }

        $albums = $music->albumsOfArtist($name, $user);
        $discography = $discographyView->build($profile?->getDiscography() ?? [], $name, $user, $albumFavorites->releaseGroupIdsForUser($user));

        return $this->render('library/artist.html.twig', [
            'artist' => $name,
            'profile' => $profile,
            'newVideoIds' => $newVideoIds,
            'albums' => $albums,
            'discography' => $discography,
            'songs' => $songs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * The library's special folders: _unclassified (files app:import could not
     * identify, kept as they are) and _discover (albums fetched by
     * app:discovery-dl), grouped by sub-folder.
     */
    #[Route('/folder/{name}', name: 'app_folder', requirements: ['name' => 'unclassified|discover'])]
    public function folder(string $name, #[CurrentUser] ?User $user, MusicRepository $music, #[Autowire('%app.music_dir%')] string $musicDir): Response
    {
        $root = rtrim($musicDir, '/') . '/_' . $name;
        $groups = [];
        foreach ($music->findInFolder($root, $user) as $song) {
            $relative = substr(\dirname((string) $song->getFile()), \strlen($root) + 1);
            $groups[$relative !== false && $relative !== '' ? $relative : '.'][] = $song;
        }

        return $this->render('library/folder.html.twig', [
            'name' => $name,
            'folder' => '_' . $name,
            'groups' => $groups,
            'count' => array_sum(array_map('count', $groups)),
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    #[Route('/album/{name}', name: 'app_album', requirements: ['name' => '.+'])]
    public function album(string $name, #[CurrentUser] ?User $user, MusicRepository $music): Response
    {
        $songs = $music->findByAlbum($name, $user);
        if ($songs === []) {
            throw $this->createNotFoundException('Album not found or has no visible tracks.');
        }

        return $this->render('library/album.html.twig', [
            'album' => $name,
            'songs' => $songs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * @return int[]
     */
    private function favoriteIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $this->favorites->favoritedSongIds($user);
    }

    /**
     * @return int[]
     */
    private function listenLaterIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $this->listenLater->songIdsForUser($user);
    }

    /**
     * @return int[]
     */
    private function offlineIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $this->offline->songIdsForUser($user);
    }
}
