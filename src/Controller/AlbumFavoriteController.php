<?php

namespace App\Controller;

use App\Entity\AlbumFavorite;
use App\Entity\User;
use App\Repository\AlbumFavoriteRepository;
use App\Repository\ArtistProfileRepository;
use App\Service\Artist\DiscographyView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Hearts on the albums of an artist's discography (to listen to, often not
 * in the library yet) and the page listing them. The heart buttons reuse the
 * track favorites' JS (data-fav-toggle, same CSRF token, {favorited} answer).
 */
#[IsGranted('ROLE_USER')]
class AlbumFavoriteController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'favorite';
    private const MBID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly AlbumFavoriteRepository $favorites,
        private readonly ArtistProfileRepository $profiles,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/favorites/discography', name: 'app_album_favorites', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, DiscographyView $view): Response
    {
        $favorites = $this->favorites->findForUser($user);
        $hearted = array_map(static fn(AlbumFavorite $f) => $f->getReleaseGroupId(), $favorites);

        // Cards built like the artist page's (library album, owned tracks, track list), per artist.
        $byArtist = [];
        foreach ($favorites as $f) {
            $byArtist[$f->getArtist()][] = $f;
        }
        $cards = [];
        foreach ($byArtist as $artist => $favs) {
            $known = array_column($this->profiles->findOneByName((string) $artist)?->getDiscography() ?? [], null, 'id');
            $entries = array_map(static fn(AlbumFavorite $f): array => $known[$f->getReleaseGroupId()] ?? [
                'id' => $f->getReleaseGroupId(), 'title' => $f->getTitle(), 'type' => $f->getType(), 'date' => $f->getReleaseDate(),
            ], $favs);
            foreach ($view->build($entries, (string) $artist, $user, $hearted, false) as $card) {
                $cards[$card['id']] = $card;
            }
        }

        return $this->render('favorite/albums.html.twig', [
            // Latest hearted first, as stored.
            'cards' => array_values(array_filter(array_map(static fn(string $id) => $cards[$id] ?? null, $hearted))),
        ]);
    }

    #[Route('/favorites/discography/toggle/{id}', name: 'app_album_favorite_toggle', methods: ['POST'], requirements: ['id' => self::MBID])]
    public function toggle(string $id, Request $request, #[CurrentUser] User $user, ClockInterface $clock): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) ($request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_token', '')))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $existing = $this->favorites->findOneForUser($user, $id);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();

            return new JsonResponse(['favorited' => false]);
        }

        // What is stored comes from the artist's discography, not from the request.
        $artist = (string) $request->query->get('artist', '');
        $entry = null;
        foreach ($this->profiles->findOneByName($artist)?->getDiscography() ?? [] as $album) {
            if ($album['id'] === $id) {
                $entry = $album;
                break;
            }
        }
        if ($entry === null) {
            return new JsonResponse(['error' => 'Album not found in this artist\'s discography'], Response::HTTP_NOT_FOUND);
        }

        $this->em->persist(new AlbumFavorite($user, $id, $artist, (string) $entry['title'], (string) ($entry['type'] ?? 'Album'), $entry['date'] ?? null, $clock->now()));
        $this->em->flush();

        return new JsonResponse(['favorited' => true]);
    }
}
