<?php

namespace App\Controller;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\ListenLaterRepository;
use App\Repository\MusicRepository;
use App\Repository\OfflineSongRepository;
use App\Repository\PlayCountRepository;
use App\Service\MetadataDumper;
use App\Service\RatingReader;
use App\Service\SongVersionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Single-track detail page. Ported from the legacy `song` / `details` /
 * `get_song_data` actions (which fed the SPA's track detail panel) plus
 * `get_song_versions` / `restore_song_version` for the owner-only backup
 * browser.
 */
class SongController extends AbstractController
{
    #[Route('/song/{id}', name: 'app_song', requirements: ['id' => '\d+'])]
    public function show(
        int $id,
        #[CurrentUser] ?User $user,
        MusicRepository $music,
        FavoriteRepository $favorites,
        ListenLaterRepository $listenLater,
        OfflineSongRepository $offline,
        PlayCountRepository $playCounts,
        SongVersionManager $versions,
        RatingReader $ratingReader,
        MetadataDumper $dumper,
    ): Response {
        $song = $music->find($id);
        if ($song === null) {
            throw $this->createNotFoundException('Track not found.');
        }

        $this->assertVisible($song, $user);

        $isOwner = $user !== null && ($song->getOwner() === $user || $user->isSuperAdmin());

        return $this->render('song/show.html.twig', [
            'song' => $song,
            'rating' => $ratingReader->read((string) $song->getFile()),
            'rawMetadata' => $dumper->dump((string) $song->getFile()),
            'isFavorite' => $user !== null && $favorites->findOneForUser($user, $song) !== null,
            'isListenLater' => $user !== null && $listenLater->findOneForUser($user, $song) !== null,
            'isOffline' => $user !== null && $offline->findOneForUser($user, $song) !== null,
            'playCount' => $user !== null ? $playCounts->findOneForUser($user, $song)?->getPlayCount() ?? 0 : 0,
            'isOwner' => $isOwner,
            'versions' => $isOwner ? $versions->listVersions($song) : [],
        ]);
    }

    #[Route('/song/{id}/versions/restore', name: 'app_song_version_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function restoreVersion(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
        MusicRepository $music,
        SongVersionManager $versions,
        EntityManagerInterface $em,
    ): Response {
        $song = $music->find($id);
        if ($song === null) {
            throw $this->createNotFoundException('Track not found.');
        }

        if ($song->getOwner() !== $user && !$user->isSuperAdmin()) {
            throw $this->createAccessDeniedException('Not your track.');
        }

        if (!$this->isCsrfTokenValid('song_version_restore_' . $song->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $versionName = (string) $request->request->get('version_name', '');

        try {
            $versions->restore($song, $versionName);
            $em->flush();
            $this->addFlash('success', 'Version restored successfully.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_song', ['id' => $song->getId()]);
    }

    private function assertVisible(Music $song, ?User $user): void
    {
        if (!$song->isPrivate()) {
            return;
        }
        if ($user !== null && ($user->isSuperAdmin() || $song->getOwner() === $user)) {
            return;
        }

        throw $this->createAccessDeniedException('This track is private.');
    }
}
