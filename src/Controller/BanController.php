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
use App\Exception\UserFacingException;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Not this song": bans the track's recording (see BanList). From the track
 * menu (JSON, called by player.js) or the song page (form).
 */
#[IsGranted('ROLE_ADMIN')]
class BanController extends AbstractController
{
    #[Route('/song/{id}/ban', name: 'app_song_ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ban(int $id, Request $request, MusicRepository $music, BanList $banList, TranslatorInterface $translator): Response
    {
        $json = $request->headers->get('X-Requested-With') === 'fetch';
        if (!$this->isCsrfTokenValid('ban', (string) ($request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_token')))) {
            return $json ? new JsonResponse(['error' => $translator->trans('error.csrf_invalid')], Response::HTTP_FORBIDDEN) : throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $song = $music->find($id);
        if ($song === null) {
            return $json ? new JsonResponse(['error' => $translator->trans('error.track_not_found')], Response::HTTP_NOT_FOUND) : throw $this->createNotFoundException();
        }
        $label = trim($song->getArtist() . ' — ' . $song->getDisplayTitle(), ' —');
        $artist = $song->getArtist();

        try {
            $result = $banList->ban($song);
        } catch (\RuntimeException $e) {
            if ($json) {
                // player.js prefixes it with its own "Not banned: "
                return new JsonResponse(['error' => $e instanceof UserFacingException ? $e->trans($translator) : $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->addFlash('error', UserFacingException::wrap($e, 'flash.ban.failed'));

            return $this->redirectToRoute('app_song', ['id' => $id]);
        }

        $message = $result['restored'] !== null
            ? new TranslatableMessage('flash.ban.banned_restored', ['track' => $label])
            : new TranslatableMessage('flash.ban.banned_removed', ['track' => $label]);
        if ($json) {
            return new JsonResponse(['banned' => true, 'removed' => $result['restored'] === null, 'message' => $message->trans($translator)]);
        }
        $this->addFlash('success', $message);

        return $result['restored'] !== null
            ? $this->redirectToRoute('app_song', ['id' => $id])
            : ($artist ? $this->redirectToRoute('app_artist', ['name' => $artist]) : $this->redirectToRoute('app_library'));
    }
}
