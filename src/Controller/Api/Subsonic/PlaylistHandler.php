<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\OfflineSong;
use App\Entity\Playlist;
use App\Entity\PlaylistSong;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Repository\OfflineSongRepository;
use App\Repository\PlaylistRepository;
use App\Repository\PlaylistSongRepository;
use App\Subsonic\IdCodec;
use App\Subsonic\LibraryQuery;
use App\Subsonic\SongMapper;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Playlists, plus the shared offline list exposed as the virtual playlist
 * "pl-offline" (OfflineSong rows — the same list as the website's /offline
 * page). Adding/removing songs in pl-offline from any device changes that
 * list; clients set to auto-download it then follow on every sync.
 */
final class PlaylistHandler implements SubsonicHandler
{
    private const OFFLINE_NAME = 'Hors ligne';

    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly PlaylistSongRepository $playlistSongs,
        private readonly OfflineSongRepository $offline,
        private readonly MusicRepository $music,
        private readonly LibraryQuery $library,
        private readonly SongMapper $songs,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function methods(): array
    {
        return ['getPlaylists', 'getPlaylist', 'createPlaylist', 'updatePlaylist', 'deletePlaylist'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        return match ($method) {
            'getPlaylists' => ['playlists' => ['playlist' => $this->all($user)]],
            'getPlaylist' => ['playlist' => $this->one($request->require('id'), $user)],
            'createPlaylist' => ['playlist' => $this->create($request, $user)],
            'updatePlaylist' => $this->update($request, $user),
            'deletePlaylist' => $this->delete($request->require('id'), $user),
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
    }

    /** @return list<array<string, mixed>> */
    private function all(User $user): array
    {
        $list = [$this->summary(IdCodec::OFFLINE_PLAYLIST, self::OFFLINE_NAME, $user, $this->offlineIds($user), null, false)];
        foreach ($this->playlists->findForUser($user) as $playlist) {
            $list[] = $this->summary(IdCodec::playlist((int) $playlist->getId()), (string) $playlist->getName(), $user, $this->playlistIds($playlist), $playlist, !$playlist->isPrivate());
        }

        return $list;
    }

    /** @return array<string, mixed> */
    private function one(string $id, User $user): array
    {
        if ($id === IdCodec::OFFLINE_PLAYLIST) {
            $ids = $this->offlineIds($user);

            return $this->summary($id, self::OFFLINE_NAME, $user, $ids, null, false) + ['entry' => $this->entries($user, $ids)];
        }
        $playlist = $this->find($id, $user, write: false);
        $ids = $this->playlistIds($playlist);

        return $this->summary($id, (string) $playlist->getName(), $user, $ids, $playlist, !$playlist->isPrivate()) + ['entry' => $this->entries($user, $ids)];
    }

    /**
     * createPlaylist: new playlist from `name` + `songId…`, or, with
     * `playlistId`, replaces that playlist's songs.
     *
     * @return array<string, mixed>
     */
    private function create(SubsonicRequest $request, User $user): array
    {
        $songIds = array_map(fn(string $s): int => $this->songId($s), $request->getAll('songId'));
        $playlistId = $request->get('playlistId');

        if ($playlistId === IdCodec::OFFLINE_PLAYLIST) {
            foreach ($this->offline->findForUser($user) as $entry) {
                $this->em->remove($entry);
            }
            $this->em->flush();
            $this->addOffline($user, $songIds);
            $this->em->flush();

            return $this->one(IdCodec::OFFLINE_PLAYLIST, $user);
        }

        if ($playlistId !== null && $playlistId !== '') {
            $playlist = $this->find($playlistId, $user, write: true);
            foreach ($playlist->getItems()->toArray() as $item) {
                $playlist->removeItem($item);
            }
            $this->em->flush();
        } else {
            $playlist = new Playlist();
            $playlist->setOwner($user);
            $playlist->setName($request->require('name'));
            $playlist->setPublicId(bin2hex(random_bytes(8)));
            $playlist->setIsPrivate(true);
            $this->em->persist($playlist);
        }
        if (($name = $request->get('name')) !== null && $name !== '') {
            $playlist->setName($name);
        }
        $this->addToPlaylist($playlist, $user, $songIds);
        $this->em->flush();

        return $this->one(IdCodec::playlist((int) $playlist->getId()), $user);
    }

    /** @return array{} */
    private function update(SubsonicRequest $request, User $user): array
    {
        $id = $request->require('playlistId');
        $add = array_map(fn(string $s): int => $this->songId($s), $request->getAll('songIdToAdd'));
        $remove = array_map('intval', $request->getAll('songIndexToRemove'));
        // Remove from the end first so earlier indexes stay valid.
        rsort($remove);

        if ($id === IdCodec::OFFLINE_PLAYLIST) {
            $entries = $this->offline->findForUser($user);
            foreach ($remove as $index) {
                if (isset($entries[$index])) {
                    $this->em->remove($entries[$index]);
                }
            }
            $this->em->flush();
            $this->addOffline($user, $add);
            $this->em->flush();

            return [];
        }

        $playlist = $this->find($id, $user, write: true);
        if (($name = $request->get('name')) !== null && $name !== '') {
            $playlist->setName($name);
        }
        if (($comment = $request->get('comment')) !== null) {
            $playlist->setDescription($comment !== '' ? $comment : null);
        }
        if ($request->get('public') !== null) {
            $playlist->setIsPrivate(!$request->bool('public'));
        }
        $items = $this->playlistSongs->findForPlaylist($playlist);
        foreach ($remove as $index) {
            if (isset($items[$index])) {
                $playlist->removeItem($items[$index]);
            }
        }
        $this->em->flush();
        $this->addToPlaylist($playlist, $user, $add);
        $this->em->flush();

        return [];
    }

    /** @return array{} */
    private function delete(string $id, User $user): array
    {
        if ($id === IdCodec::OFFLINE_PLAYLIST) {
            throw new SubsonicException(SubsonicException::NOT_AUTHORIZED, 'The offline list cannot be deleted');
        }
        $this->playlists->remove($this->find($id, $user, write: true));

        return [];
    }

    /** @param list<int> $songIds */
    private function addToPlaylist(Playlist $playlist, User $user, array $songIds): void
    {
        $present = array_flip($playlist->getId() !== null ? $this->playlistIds($playlist) : []);
        $order = $playlist->getId() !== null ? $this->playlistSongs->nextSortOrder($playlist) : 1;
        foreach ($songIds as $songId) {
            // playlist_songs has a (playlist, song) primary key: no duplicates.
            if (isset($present[$songId])) {
                continue;
            }
            $item = new PlaylistSong();
            $item->setSong($this->visibleSong($songId, $user));
            $item->setSortOrder($order++);
            $item->setAddedBy($user);
            $playlist->addItem($item);
            $present[$songId] = true;
        }
    }

    /** @param list<int> $songIds */
    private function addOffline(User $user, array $songIds): void
    {
        $present = array_flip($this->offlineIds($user));
        $order = $this->offline->nextSortOrder($user);
        foreach ($songIds as $songId) {
            if (isset($present[$songId])) {
                continue;
            }
            $entry = new OfflineSong();
            $entry->setUser($user);
            $entry->setSong($this->visibleSong($songId, $user));
            $entry->setSortOrder($order++);
            $this->em->persist($entry);
            $present[$songId] = true;
        }
    }

    /**
     * @param list<int> $songIds
     *
     * @return array<string, mixed>
     */
    private function summary(string $id, string $name, User $user, array $songIds, ?Playlist $playlist, bool $public): array
    {
        $rows = $this->library->songsByIds($user, $songIds);
        $created = SongMapper::iso($playlist?->getCreatedAt()?->format('Y-m-d H:i:s')) ?? SongMapper::iso('2000-01-01 00:00:00');

        return [
            'id' => $id,
            'name' => $name,
            'comment' => $playlist?->getDescription(),
            'owner' => $user->getUserIdentifier(),
            'public' => $public,
            'songCount' => \count($rows),
            'duration' => array_sum(array_map(static fn(array $r): int => (int) $r['duration'], $rows)),
            'created' => $created,
            'changed' => SongMapper::iso(new \DateTimeImmutable()->format('Y-m-d H:i:s')),
            'coverArt' => $rows !== [] && $rows[0]['album_key'] !== null ? IdCodec::album((string) $rows[0]['album_key']) : null,
            'readonly' => false,
        ];
    }

    /**
     * @param list<int> $songIds
     *
     * @return list<array<string, mixed>>
     */
    private function entries(User $user, array $songIds): array
    {
        return $this->songs->children($user, $this->library->songsByIds($user, $songIds));
    }

    /** @return list<int> */
    private function offlineIds(User $user): array
    {
        return array_values(array_map(static fn(OfflineSong $o): int => (int) $o->getSong()?->getId(), $this->offline->findForUser($user)));
    }

    /** @return list<int> */
    private function playlistIds(Playlist $playlist): array
    {
        return array_map(static fn(PlaylistSong $ps): int => (int) $ps->getSong()?->getId(), $this->playlistSongs->findForPlaylist($playlist));
    }

    private function find(string $id, User $user, bool $write): Playlist
    {
        $decoded = IdCodec::decode($id);
        $playlist = $decoded['type'] === 'playlist' ? $this->playlists->find((int) $decoded['value']) : null;
        if ($playlist === null) {
            throw SubsonicException::notFound('Playlist');
        }
        $isOwner = $playlist->getOwner()?->getId() === $user->getId();
        if (!$isOwner && ($write || $playlist->isPrivate())) {
            throw new SubsonicException(SubsonicException::NOT_AUTHORIZED, 'Not your playlist');
        }

        return $playlist;
    }

    private function songId(string $id): int
    {
        $decoded = IdCodec::decode($id);
        if ($decoded['type'] !== 'song') {
            throw SubsonicException::notFound('Song');
        }

        return (int) $decoded['value'];
    }

    private function visibleSong(int $songId, User $user): \App\Entity\Music
    {
        $song = $this->music->find($songId);
        if ($song === null || !$this->music->isVisibleTo($song, $user)) {
            throw SubsonicException::notFound("Song {$songId}");
        }

        return $song;
    }
}
