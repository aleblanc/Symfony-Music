<?php

namespace App\Controller;

use App\Repository\MusicRepository;
use App\Service\Ban\BanList;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Not this song": bans the track's recording (see BanList). From the track
 * menu (JSON, called by player.js) or the song page (form).
 */
#[IsGranted('ROLE_ADMIN')]
class BanController extends AbstractController
{
    #[Route('/song/{id}/ban', name: 'app_song_ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ban(int $id, Request $request, MusicRepository $music, BanList $banList): Response
    {
        $json = $request->headers->get('X-Requested-With') === 'fetch';
        if (!$this->isCsrfTokenValid('ban', (string) ($request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_token')))) {
            return $json ? new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN) : throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $song = $music->find($id);
        if ($song === null) {
            return $json ? new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND) : throw $this->createNotFoundException();
        }
        $label = trim($song->getArtist() . ' — ' . $song->getDisplayTitle(), ' —');
        $artist = $song->getArtist();

        try {
            $result = $banList->ban($song);
        } catch (\RuntimeException $e) {
            if ($json) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->addFlash('error', 'Not banned: ' . $e->getMessage());

            return $this->redirectToRoute('app_song', ['id' => $id]);
        }

        $message = $result['restored'] !== null
            ? \sprintf('%s banned: the previous version is back (app:scan to refresh its details).', $label)
            : \sprintf('%s banned and removed from the library: this recording will not be downloaded again for it.', $label);
        if ($json) {
            return new JsonResponse(['banned' => true, 'removed' => $result['restored'] === null, 'message' => $message]);
        }
        $this->addFlash('success', $message);

        return $result['restored'] !== null
            ? $this->redirectToRoute('app_song', ['id' => $id])
            : ($artist ? $this->redirectToRoute('app_artist', ['name' => $artist]) : $this->redirectToRoute('app_library'));
    }
}
