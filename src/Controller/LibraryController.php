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
        $newVideoIds = $newPostIds = $newConcertIds = [];
        if ($profile !== null) {
            $newVideoIds = array_column($profile->newVideos($clock->now()), 'id');
            $newPostIds = array_column($profile->newPosts($clock->now()), 'id');
            $newConcertIds = array_column($profile->newConcerts($clock->now()), 'id');
            if ($profile->getVideos() !== [] || $profile->getPosts() !== [] || $profile->getConcerts() !== []) {
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
            'newPostIds' => $newPostIds,
            'newConcertIds' => $newConcertIds,
            'albums' => $albums,
            // Duos / trios / feat. this artist appears in, and the library artists of a collaboration credit.
            'collaborations' => $music->collaborationsOf($name, $user),
            'members' => $music->membersOf($name, $user),
            'discography' => $discography,
            // Library album name => its discography entry (track list behind the "?" of the album cards).
            'albumTracklists' => array_column(array_filter($discography, static fn(array $d): bool => $d['owned'] !== null && \is_array($d['tracklist'] ?? null)), null, 'owned'),
            'songs' => $songs,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * The library's special folders: _unclassified (files app:import could not
     * identify, kept as they are) and _discover (albums to discover, e.g.
     * from your Favorite discography), grouped by sub-folder.
     */
    #[Route('/folder/{name}', name: 'app_folder', requirements: ['name' => 'unclassified|discover'])]
    public function folder(string $name, #[CurrentUser] ?User $user, MusicRepository $music, DiscographyView $discographyView, #[Autowire('%app.music_dir%')] string $musicDir): Response
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
            // Sub-folder => its album's MusicBrainz track list, when its tracks form a known album.
            'tracklists' => array_filter(array_map(fn(array $songs): ?array => ($album = $songs[0]->getAlbum()) !== null && $album !== ''
                ? $this->albumTracklist($songs, $album, $user, $discographyView) : null, $groups)),
            'count' => array_sum(array_map('count', $groups)),
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * Tracks by star rating: one tab per note (5 to 1) and the unrated ones.
     */
    #[Route('/ratings/{stars}', name: 'app_ratings', requirements: ['stars' => '[0-5]'], defaults: ['stars' => 5])]
    public function ratings(int $stars, Request $request, #[CurrentUser] ?User $user, MusicRepository $music): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 100;
        $paginator = $music->findByRating($stars, $user, $page, $perPage);
        $total = \count($paginator);

        return $this->render('library/ratings.html.twig', [
            'stars' => $stars,
            'counts' => $music->countByRating($user),
            'songs' => iterator_to_array($paginator),
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'total' => $total,
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    #[Route('/album/{name}', name: 'app_album', requirements: ['name' => '.+'])]
    public function album(string $name, #[CurrentUser] ?User $user, MusicRepository $music, DiscographyView $discographyView): Response
    {
        $songs = $music->findByAlbum($name, $user);
        if ($songs === []) {
            throw $this->createNotFoundException('Album not found or has no visible tracks.');
        }

        return $this->render('library/album.html.twig', [
            'album' => $name,
            'songs' => $songs,
            // Its MusicBrainz track list (most listened tracks), for the "?" next to the title.
            'tracklist' => $this->albumTracklist($songs, $name, $user, $discographyView),
            'favoriteIds' => $this->favoriteIds($user),
            'listenLaterIds' => $this->listenLaterIds($user),
            'offlineIds' => $this->offlineIds($user),
        ]);
    }

    /**
     * The discography entry of an album of the library, found from its
     * album artist (else its most frequent artist).
     *
     * @param list<\App\Entity\Music> $songs
     *
     * @return ?array<string, mixed>
     */
    private function albumTracklist(array $songs, string $album, ?User $user, DiscographyView $discographyView): ?array
    {
        $artists = array_count_values(array_filter(array_map(static fn($s): string => (string) ($s->getAlbumArtist() ?: $s->getArtist()), $songs)));
        arsort($artists);
        $artist = (string) array_key_first($artists);

        return $artist !== '' ? $discographyView->forAlbum($artist, $album, $user) : null;
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
