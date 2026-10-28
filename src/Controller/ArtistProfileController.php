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
use Symfony\Component\Translation\TranslatableMessage;

/**
 * "Refresh" button of an artist page: same as
 * `app:cron:artist-info --artist=NAME` + `app:cron:artist-videos --artist=NAME`.
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
                $this->addFlash('error', new TranslatableMessage('flash.artist.youtube_unreachable'));
            }
            $em->flush();
            $this->addFlash($profile->isFound() ? 'success' : 'error', new TranslatableMessage($profile->isFound() ? 'flash.artist.info_updated' : 'flash.artist.not_found_musicbrainz'));
        } catch (EnrichmentException $e) {
            $this->addFlash('error', new TranslatableMessage('flash.artist.musicbrainz_error', ['error' => $e->getMessage()]));
        }

        return $this->redirectToRoute('app_artist', ['name' => $name]);
    }
}
