<?php

namespace App\Service;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\HistoryRepository;
use App\Repository\MixRepository;
use App\Repository\MusicRepository;
use App\Repository\PlayCountRepository;
use App\Repository\UserRepository;

/**
 * All the "discovery" computation logic: trending tracks, the guest-facing
 * "Explore" shelves, the logged-in "For You" recommendations, artist
 * suggestions, radio and queue hydration. Ported from the legacy
 * `get_trending`, `get_explore`, `get_recommendations`,
 * `get_recommended_artists`, `get_radio_tracks` and `get_queue_songs`
 * actions. Mix generation/expiry lives in {@see MixRepository} (it is pure
 * data maintenance, not a "recommendation"); this service only reads mixes
 * back out for the hub page teaser.
 *
 * None of this is a real ML/collaborative-filtering engine — like the
 * legacy app, it is simple SQL aggregation (top played, top genre, top
 * artist) plus random sampling for "discover something new" shelves.
 */
class RecommendationService
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly PlayCountRepository $playCounts,
        private readonly HistoryRepository $history,
        private readonly FavoriteRepository $favorites,
        private readonly UserRepository $users,
        private readonly MixRepository $mixes,
    ) {}

    /**
     * @return Music[]
     */
    public function trending(?User $viewer, int $limit = 60, int $offset = 0): array
    {
        return $this->music->trending($viewer, $limit, $offset);
    }

    /**
     * Shelves for the public "Explore" page. Available to guests; a couple
     * of extra shelves appear once logged in. Ported (simplified — no
     * "From Your Artists" shelf, which depends on the follow system of
     * Feature 6) from the legacy `get_explore` action.
     *
     * @return array<int, array{title: string, type: string, items: array<int, mixed>}>
     */
    public function explore(?User $viewer): array
    {
        $shelves = [];
        $historyIds = $viewer !== null ? $this->history->songIdsForUser($viewer) : [];

        $discoverSongs = $this->music->randomVisible($viewer, 15, $historyIds);
        if ($discoverSongs !== []) {
            $shelves[] = ['title' => 'Discover Songs', 'type' => 'songs', 'items' => $discoverSongs];
        }

        $discoverAlbums = $this->music->randomAlbums($viewer, 10, $historyIds);
        if ($discoverAlbums !== []) {
            $shelves[] = ['title' => 'Discover Albums', 'type' => 'albums', 'items' => $discoverAlbums];
        }

        $discoverArtists = $this->resolveArtistShelf($viewer, 10, $historyIds);
        if ($discoverArtists !== []) {
            $shelves[] = ['title' => 'Discover Artists', 'type' => 'artists', 'items' => $discoverArtists];
        }

        if ($viewer !== null) {
            $frequentlyPlayed = $this->playCounts->topSongs($viewer, 15);
            if ($frequentlyPlayed !== []) {
                $shelves[] = ['title' => 'Frequently Played', 'type' => 'songs', 'items' => $frequentlyPlayed];
            }
        }

        return $shelves;
    }

    /**
     * Shelves for the logged-in "For You" page. Ported (simplified — no
     * "Your Playlists" shelf, and no cross-request 5-minute cache like the
     * legacy `sys_get_temp_dir()` micro-cache) from the legacy
     * `get_recommendations` action.
     *
     * @return array<int, array{title: string, type: string, items: array<int, mixed>}>
     */
    public function recommendations(User $user): array
    {
        $shelves = [];
        $historyIds = $this->history->songIdsForUser($user);

        $recent = $this->history->recentSongs($user, 10);
        if ($recent !== []) {
            $shelves[] = ['title' => 'Recently Played', 'type' => 'songs', 'items' => $recent];
        }

        $topArtist = $this->playCounts->topArtist($user);
        if ($topArtist !== null) {
            $moreFromArtist = $this->music->randomAlbums($user, 10, $historyIds, $topArtist);
            if ($moreFromArtist !== []) {
                $shelves[] = ['title' => 'More from ' . $topArtist, 'type' => 'albums', 'items' => $moreFromArtist];
            }
        }

        $topGenres = $this->playCounts->topGenres($user, 2);
        if ($topGenres !== []) {
            $genreSongs = [];
            foreach ($topGenres as $genre) {
                $genreSongs = array_merge($genreSongs, $this->music->visibleIds($user, genre: $genre, excludeIds: $historyIds));
            }
            $genreSongs = array_unique($genreSongs);
            shuffle($genreSongs);
            $genreSongs = $this->music->hydrateByIds(\array_slice($genreSongs, 0, 15));
            if ($genreSongs !== []) {
                $shelves[] = ['title' => 'Your Genre Mix', 'type' => 'songs', 'items' => $genreSongs];
            }
        }

        $recentFavorites = $this->favorites->recentFavorites($user, 10);
        if ($recentFavorites !== []) {
            $shelves[] = ['title' => 'Your Favorites', 'type' => 'songs', 'items' => $recentFavorites];
        }

        $discoverSongs = $this->music->randomVisible($user, 15, $historyIds);
        if ($discoverSongs !== []) {
            $shelves[] = ['title' => 'Discover New Songs', 'type' => 'songs', 'items' => $discoverSongs];
        }

        $discoverAlbums = $this->music->randomAlbums($user, 10, $historyIds);
        if ($discoverAlbums !== []) {
            $shelves[] = ['title' => 'Discover New Albums', 'type' => 'albums', 'items' => $discoverAlbums];
        }

        $discoverArtists = $this->resolveArtistShelf($user, 10, $historyIds);
        if ($discoverArtists !== []) {
            $shelves[] = ['title' => 'Discover Artists', 'type' => 'artists', 'items' => $discoverArtists];
        }

        $frequentlyPlayed = $this->playCounts->topSongs($user, 15);
        if ($frequentlyPlayed !== []) {
            $shelves[] = ['title' => 'Frequently Played', 'type' => 'songs', 'items' => $frequentlyPlayed];
        }

        return $shelves;
    }

    /**
     * Random registered users to suggest, excluding the viewer and an
     * optional target user (e.g. "people who might follow X"). Ported from
     * the legacy `get_recommended_artists` action.
     *
     * @return User[]
     */
    public function recommendedArtists(?User $viewer, ?int $targetId, int $limit = 20): array
    {
        return $this->users->randomSuggested($viewer, $targetId, $limit);
    }

    /**
     * A random "radio" playlist seeded from one track's genre/artist. Ported
     * from the legacy `get_radio_tracks` action. Returns an empty array if
     * the seed track doesn't exist (rather than falling back to fully random
     * tracks like the legacy did for a *missing* seed id — a non-existent
     * seed is treated as a caller error here).
     *
     * @return Music[]
     */
    public function radioTracks(?User $viewer, int $seedId, int $limit = 15): array
    {
        $seed = $this->music->find($seedId);
        if ($seed === null) {
            return $this->music->randomVisible($viewer, $limit);
        }

        $ids = $this->music->radioCandidateIds($viewer, $seed->getArtist(), $seed->getGenre(), $seedId);
        shuffle($ids);

        return $this->music->hydrateByIds(\array_slice($ids, 0, $limit));
    }

    /**
     * Hydrates a client-supplied queue (array of song ids) back into track
     * data, preserving the given order. Ported from the legacy
     * `get_queue_songs` action, which (deliberately, like here) does not
     * re-check visibility: by the time a track is in a client's queue it was
     * already served to that client once.
     *
     * @param int[] $ids
     *
     * @return Music[]
     */
    public function queueSongs(array $ids): array
    {
        return $this->music->hydrateByIds($ids);
    }

    /**
     * Whether $song may be shown to $viewer (public, or the viewer's own
     * private track, or a super admin). Exposed so the mix detail page can
     * filter out private tracks the viewer should not see, without the
     * controller needing to depend on {@see MusicRepository} directly.
     */
    public function isVisibleTo(Music $song, ?User $viewer): bool
    {
        return $this->music->isVisibleTo($song, $viewer);
    }

    /**
     * Runs the mixes housekeeping (expire old, seed if empty) that the
     * legacy app ran inline on every `get_mixes` call.
     */
    public function refreshMixes(): void
    {
        $this->mixes->expireOlderThan(new \DateTimeImmutable('-3 days'));
        $this->mixes->ensureSeeded();
    }

    /**
     * Distinct artist names resolved to a representative id, and — when the
     * name matches a registered user's display name — flagged as such.
     *
     * @param int[] $excludeIds
     *
     * @return array<int, array{name: string, id: int, isUser: bool}>
     */
    private function resolveArtistShelf(?User $viewer, int $limit, array $excludeIds): array
    {
        $candidates = $this->music->distinctArtistNames($viewer, $limit, $excludeIds);

        $resolved = [];
        foreach ($candidates as $candidate) {
            $matchedUser = $this->users->findOneByArtistName($candidate['name']);
            $resolved[] = [
                'name' => $candidate['name'],
                'id' => $matchedUser?->getId() ?? $candidate['id'],
                'isUser' => $matchedUser !== null,
            ];
        }

        return $resolved;
    }
}
