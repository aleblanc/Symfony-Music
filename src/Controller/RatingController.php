<?php

namespace App\Controller;

use App\Repository\MusicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Star rating of a track from anywhere (track lists, song page): one click
 * sets 1 to 5 stars, 0 removes the rating. One note per track (Music::$rating).
 * JSON, called by player.js.
 */
#[IsGranted('ROLE_USER')]
class RatingController extends AbstractController
{
    #[Route('/rating/{id}', name: 'app_rating_set', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function set(int $id, Request $request, MusicRepository $music, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('rating', (string) $request->headers->get('X-CSRF-TOKEN', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }
        $song = $music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND);
        }
        $stars = $request->request->getInt('stars');
        if ($stars < 0 || $stars > 5) {
            return new JsonResponse(['error' => 'stars must be 0 (none) to 5'], Response::HTTP_BAD_REQUEST);
        }

        $song->setRating($stars);
        $em->flush();

        return new JsonResponse(['stars' => $song->getRating() ?? 0]);
    }
}
