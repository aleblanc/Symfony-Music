<?php

namespace App\Controller;

use App\Repository\MusicRepository;
use App\Service\Artist\ArtistProfileUpdater;
use App\Service\Enrichment\EnrichmentException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Refresh" button of an artist page: same as
 * `app:artist:fetch --artist=NAME` + `app:artist:videos --artist=NAME`.
 */
#[IsGranted('ROLE_ADMIN')]
class ArtistProfileController extends AbstractController
{
    #[Route('/artist-refresh', name: 'app_artist_refresh', methods: ['POST'])]
    public function refresh(Request $request, MusicRepository $music, ArtistProfileUpdater $updater, EntityManagerInterface $em): Response
    {
        $name = (string) $request->request->get('artist');
        if (!$this->isCsrfTokenValid('artist_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $albums = $music->artistAlbums()[$name] ?? null;
        if ($albums === null) {
            throw $this->createNotFoundException('Artist not found.');
        }

        try {
            $profile = $updater->refreshInfo($name, $albums);
            if ($updater->refreshVideos($profile) === null) {
                $this->addFlash('error', 'YouTube feed unreachable, videos not updated.');
            }
            $em->flush();
            $this->addFlash($profile->isFound() ? 'success' : 'error', $profile->isFound() ? 'Artist info updated.' : 'Artist not found on MusicBrainz.');
        } catch (EnrichmentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_artist', ['name' => $name]);
    }
}
