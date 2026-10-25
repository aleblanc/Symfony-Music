<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\Favorite;
use App\Entity\StarredAlbum;
use App\Entity\StarredArtist;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\MusicRepository;
use App\Service\PlayRecorder;
use App\Subsonic\IdCodec;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * star / unstar (songs → Favorite, the same favorites as the website;
 * albums → StarredAlbum; artists → StarredArtist), setRating (the single
 * global Music rating) and scrobble (play count + history, with the
 * client's own timestamp for listens made offline).
 */
final class AnnotationHandler implements SubsonicHandler
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly FavoriteRepository $favorites,
        private readonly PlayRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function methods(): array
    {
        return ['star', 'unstar', 'setRating', 'scrobble'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        match ($method) {
            'star', 'unstar' => $this->star($request, $user, $method === 'star'),
            'setRating' => $this->setRating($request, $user),
            'scrobble' => $this->scrobble($request, $user),
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
        $this->em->flush();

        return [];
    }

    private function star(SubsonicRequest $request, User $user, bool $on): void
    {
        // Clients send "id" for songs but also, sometimes, al-/ar- ids in it.
        foreach ($request->getAll('id') as $id) {
            $decoded = IdCodec::decode($id);
            match ($decoded['type']) {
                'song' => $this->starSong($user, (int) $decoded['value'], $on),
                'album' => $this->starKey(StarredAlbum::class, (string) $decoded['value'], $on),
                'artist' => $this->starKey(StarredArtist::class, (string) $decoded['value'], $on),
                default => throw SubsonicException::notFound("Item {$id}"),
            };
        }
        foreach ($request->getAll('albumId') as $id) {
            $this->starKey(StarredAlbum::class, $this->expect($id, 'album'), $on);
        }
        foreach ($request->getAll('artistId') as $id) {
            $this->starKey(StarredArtist::class, $this->expect($id, 'artist'), $on);
        }
    }

    private function starSong(User $user, int $songId, bool $on): void
    {
        $song = $this->music->find($songId);
        if ($song === null || !$this->music->isVisibleTo($song, $user)) {
            throw SubsonicException::notFound('Song');
        }
        $existing = $this->favorites->findOneForUser($user, $song);
        if ($on && $existing === null) {
            $favorite = new Favorite();
            $favorite->setUser($user);
            $favorite->setSong($song);
            $this->em->persist($favorite);
        } elseif (!$on && $existing !== null) {
            $this->em->remove($existing);
        }
    }

    /**
     * @param class-string<StarredAlbum|StarredArtist> $class
     */
    private function starKey(string $class, string $key, bool $on): void
    {
        $existing = $this->em->find($class, $key);
        if ($on && $existing === null) {
            $this->em->persist(new $class($key));
        } elseif (!$on && $existing !== null) {
            $this->em->remove($existing);
        }
    }

    private function setRating(SubsonicRequest $request, User $user): void
    {
        $songId = $this->expect($request->require('id'), 'song');
        $rating = $request->int('rating', -1);
        if ($rating < 0 || $rating > 5) {
            throw new SubsonicException(SubsonicException::MISSING_PARAMETER, 'rating must be 0 (none) to 5');
        }
        $song = $this->music->find((int) $songId);
        if ($song === null || !$this->music->isVisibleTo($song, $user)) {
            throw SubsonicException::notFound('Song');
        }
        $song->setRating($rating);
    }

    /**
     * submission=false is "now playing": nothing to store. Each `id` may come
     * with its own `time` (epoch ms), matched by position.
     */
    private function scrobble(SubsonicRequest $request, User $user): void
    {
        if (!$request->bool('submission', true)) {
            return;
        }
        $times = $request->getAll('time');
        foreach ($request->getAll('id') as $i => $id) {
            $song = $this->music->find((int) $this->expect($id, 'song'));
            if ($song === null || !$this->music->isVisibleTo($song, $user)) {
                throw SubsonicException::notFound('Song');
            }
            $ms = isset($times[$i]) && preg_match('/^\d+$/', $times[$i]) === 1 ? (int) $times[$i] : null;
            $playedAt = $ms !== null
                ? new \DateTimeImmutable('@' . intdiv($ms, 1000))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                : new \DateTimeImmutable();
            $this->recorder->record($user, $song, $playedAt, dedupe: true);
        }
    }

    /** Decodes $id and checks its kind; returns the key (string) or song id (numeric string). */
    private function expect(string $id, string $type): string
    {
        $decoded = IdCodec::decode($id);
        if ($decoded['type'] !== $type) {
            throw SubsonicException::notFound(ucfirst($type));
        }

        return (string) $decoded['value'];
    }
}
