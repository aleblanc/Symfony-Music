<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\FavoriteRepository;
use App\Repository\HistoryRepository;
use App\Repository\ListenLaterRepository;
use App\Repository\MusicRepository;
use App\Repository\OfflineSongRepository;
use App\Repository\PlayCountRepository;
use App\Service\Enrichment\MatchScorer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function artist(string $name, #[CurrentUser] ?User $user, MusicRepository $music, ArtistProfileRepository $profiles, EntityManagerInterface $em, ClockInterface $clock, MatchScorer $scorer): Response
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

        // The artist's whole discography (MusicBrainz), each entry pointing at the library album when there is one.
        $albums = $music->albumsOfArtist($name, $user);
        $discography = [];
        foreach ($profile?->getDiscography() ?? [] as $entry) {
            $owned = null;
            foreach ($albums as $a) {
                if ($scorer->similarity($entry['title'], $a['album']) >= 0.85) {
                    $owned = $a['album'];
                    break;
                }
            }
            $discography[] = $entry + ['owned' => $owned];
        }
        // Tracks of each album's list already in the library (same title, case/accents/punctuation ignored).
        $latin = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $key = static fn(string $t): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) ($latin?->transliterate($t) ?: mb_strtolower($t))));
        $have = [];
        foreach ($songs as $song) {
            $have[$key((string) $song->getTitle())] = true;
        }
        foreach ($discography as &$d) {
            if (\is_array($d['tracklist'] ?? null)) {
                foreach ($d['tracklist']['tracks'] as &$t) {
                    $t['have'] = isset($have[$key($t['title'])]);
                }
                unset($t);
            }
        }
        unset($d);
        // ListenBrainz listeners: a bar relative to the artist's most listened album, a star on the top 3.
        $listeners = array_filter(array_map(static fn(array $d): int => (int) ($d['listeners'] ?? 0), $discography));
        arsort($listeners);
        $top = \array_slice(array_keys($listeners), 0, min(3, intdiv(\count($listeners) + 1, 2)), true);
        $max = $listeners === [] ? 0 : max($listeners);
        foreach ($discography as $i => &$d) {
            $d['popularity'] = $max > 0 ? (int) round(100 * (int) ($d['listeners'] ?? 0) / $max) : null;
            $d['top'] = \in_array($i, $top, true);
        }
        unset($d);

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
