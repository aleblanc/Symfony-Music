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
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/favorites/discography', name: 'app_album_favorites', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, DiscographyView $view, Request $request): Response
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

        // Latest hearted first, as stored; by default re-sorted by ListenBrainz listeners
        // (distinct people, less skewed than total listens by a few fans on repeat).
        $cards = array_values(array_filter(array_map(static fn(string $id) => $cards[$id] ?? null, $hearted)));
        $max = max([0, ...array_map(static fn(array $c): int => (int) ($c['listeners'] ?? 0), $cards)]);
        foreach ($cards as &$card) {
            $card['popularity'] = $max > 0 && ($card['listeners'] ?? null) !== null ? (int) round(100 * $card['listeners'] / $max) : null;
        }
        unset($card);
        $sort = $request->query->get('sort') === 'added' ? 'added' : 'popularity';
        if ($sort === 'popularity') {
            // Stable: equal (or unknown) popularity keeps the hearted order.
            usort($cards, static fn(array $a, array $b): int => ($b['listeners'] ?? -1) <=> ($a['listeners'] ?? -1));
        }

        return $this->render('favorite/albums.html.twig', [
            'cards' => $cards,
            'sort' => $sort,
        ]);
    }

    #[Route('/favorites/discography/toggle/{id}', name: 'app_album_favorite_toggle', methods: ['POST'], requirements: ['id' => self::MBID])]
    public function toggle(string $id, Request $request, #[CurrentUser] User $user, ClockInterface $clock): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) ($request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_token', '')))) {
            return new JsonResponse(['error' => $this->translator->trans('error.csrf_invalid')], Response::HTTP_FORBIDDEN);
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
            return new JsonResponse(['error' => $this->translator->trans('error.album_not_in_discography')], Response::HTTP_NOT_FOUND);
        }

        $this->em->persist(new AlbumFavorite($user, $id, $artist, (string) $entry['title'], (string) ($entry['type'] ?? 'Album'), $entry['date'] ?? null, $clock->now()));
        $this->em->flush();

        return new JsonResponse(['favorited' => true]);
    }
}
