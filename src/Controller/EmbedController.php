<?php

namespace App\Controller;

use App\Entity\Music;
use App\Repository\MusicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Embeddable / shareable single-track mini-player. Native port of the legacy
 * `?action=embed` page. Only public tracks are embeddable.
 */
class EmbedController extends AbstractController
{
    #[Route('/embed/{id}', name: 'app_embed', requirements: ['id' => '\d+'])]
    public function show(int $id, MusicRepository $music): Response
    {
        $song = $music->find($id);
        if ($song === null || $song->isPrivate()) {
            throw $this->createNotFoundException('Song not found or is private.');
        }

        return $this->render('embed/show.html.twig', ['song' => $song]);
    }
}
