<?php

namespace App\Controller;

use App\Entity\History;
use App\Entity\PlayCount;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Repository\PlayCountRepository;
use App\Repository\PlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Records "this counts as a listen" events. In the legacy app the client
 * pinged the server after 30 seconds of playback; the front-end here does the
 * same and calls this endpoint, which bumps play counts and appends history.
 */
class PlaybackController extends AbstractController
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly PlayCountRepository $playCounts,
        private readonly PlaylistRepository $playlists,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/play/{id}', name: 'app_play_record', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function record(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $song = $this->music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND);
        }

        // Guests can play but their listens are not tracked (legacy behaviour).
        if ($user === null) {
            return new JsonResponse(['tracked' => false]);
        }

        $now = new \DateTimeImmutable();

        $playCount = $this->playCounts->findOneForUser($user, $song);
        if ($playCount === null) {
            $playCount = new PlayCount();
            $playCount->setUser($user);
            $playCount->setSong($song);
            $this->em->persist($playCount);
        } else {
            $playCount->increment();
        }
        $playCount->touch($now);

        $history = new History();
        $history->setUser($user);
        $history->setSong($song);
        $history->setPlayedAt($now);
        $this->em->persist($history);

        // Ported from the legacy `log_play`: when the client mentions which
        // playlist the track was played from, bump that playlist's view counter.
        $playlistPublicId = (string) $request->request->get('playlist_public_id', '');
        if ($playlistPublicId !== '') {
            $playlist = $this->playlists->findOneByPublicId($playlistPublicId);
            $playlist?->incrementPlayCount();
        }

        $this->em->flush();

        return new JsonResponse(['tracked' => true, 'plays' => $playCount->getPlayCount()]);
    }
}
