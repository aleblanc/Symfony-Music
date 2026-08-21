<?php

namespace App\Controller;

use App\Entity\OfflineSong;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\ListenLaterRepository;
use App\Repository\MusicRepository;
use App\Repository\OfflineSongRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Available offline" bookmarks, ported from the legacy `toggle_offline`,
 * `get_offline_songs`, `get_offline_ids`, `clear_offline` and
 * `update_offline_order` actions. Structurally the same as
 * {@see FavoriteController} (composite user+song key) with an extra manual
 * sort order. The actual on-device caching (Service Worker / Cache API) is
 * out of scope here — this only tracks which songs the user *marked* for
 * offline availability, same as the legacy `offline_songs` table did.
 */
#[IsGranted('ROLE_USER')]
class OfflineController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'offline';

    public function __construct(
        private readonly OfflineSongRepository $offline,
        private readonly MusicRepository $music,
        private readonly FavoriteRepository $favorites,
        private readonly ListenLaterRepository $listenLater,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/offline', name: 'app_offline')]
    public function index(#[CurrentUser] User $user): Response
    {
        $entries = $this->offline->findForUser($user);

        return $this->render('offline/index.html.twig', [
            'songs' => array_map(static fn(OfflineSong $o) => $o->getSong(), $entries),
            'favoriteIds' => $this->favorites->favoritedSongIds($user),
            'listenLaterIds' => $this->listenLater->songIdsForUser($user),
            'offlineIds' => $this->offline->songIdsForUser($user),
        ]);
    }

    #[Route('/offline/toggle/{id}', name: 'app_offline_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $this->extractToken($request))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $song = $this->music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->offline->findOneForUser($user, $song);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();

            return new JsonResponse(['status' => 'removed', 'offline' => false]);
        }

        $entry = new OfflineSong();
        $entry->setUser($user);
        $entry->setSong($song);
        $entry->setSortOrder($this->offline->nextSortOrder($user));
        $this->em->persist($entry);
        $this->em->flush();

        return new JsonResponse(['status' => 'added', 'offline' => true]);
    }

    #[Route('/offline/order', name: 'app_offline_order', methods: ['POST'])]
    public function updateOrder(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $this->extractToken($request))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['ids']) || !\is_array($data['ids'])) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $ids = array_map('intval', $data['ids']);
        $this->offline->updateOrder($user, $ids);

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/offline/clear', name: 'app_offline_clear', methods: ['POST'])]
    public function clear(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $this->extractToken($request))) {
            if ($this->wantsJson($request)) {
                return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
            }
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->offline->clearForUser($user);

        if ($this->wantsJson($request)) {
            return new JsonResponse(['status' => 'success', 'message' => 'All offline songs removed.']);
        }

        $this->addFlash('success', 'All offline songs removed.');

        return $this->redirectToRoute('app_offline');
    }

    /**
     * Classic HTML forms carry the token as `_token`; JS `fetch()` calls
     * carry it in the `X-CSRF-TOKEN` header (no form body to place a hidden
     * field into).
     */
    private function extractToken(Request $request): string
    {
        $header = $request->headers->get('X-CSRF-TOKEN');
        if ($header !== null) {
            return $header;
        }

        return (string) $request->request->get('_token', '');
    }

    private function wantsJson(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'fetch'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
