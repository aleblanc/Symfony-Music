<?php

namespace App\Controller;

use App\Entity\Favorite;
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
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class FavoriteController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'favorite';

    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly MusicRepository $music,
        private readonly ListenLaterRepository $listenLater,
        private readonly OfflineSongRepository $offline,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/favorites', name: 'app_favorites')]
    public function index(#[CurrentUser] User $user): Response
    {
        $favorites = $this->favorites->findForUser($user);

        return $this->render('favorite/index.html.twig', [
            'songs' => array_map(static fn(Favorite $f) => $f->getSong(), $favorites),
            'favoriteIds' => $this->favorites->favoritedSongIds($user),
            'listenLaterIds' => $this->listenLater->songIdsForUser($user),
            'offlineIds' => $this->offline->songIdsForUser($user),
        ]);
    }

    #[Route('/favorites/toggle/{id}', name: 'app_favorite_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $this->extractToken($request))) {
            return new JsonResponse(['error' => $this->translator->trans('error.csrf_invalid')], Response::HTTP_FORBIDDEN);
        }

        $song = $this->music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => $this->translator->trans('error.track_not_found')], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->favorites->findOneForUser($user, $song);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();

            return new JsonResponse(['favorited' => false]);
        }

        $favorite = new Favorite();
        $favorite->setUser($user);
        $favorite->setSong($song);
        $this->em->persist($favorite);
        $this->em->flush();

        return new JsonResponse(['favorited' => true]);
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
}
