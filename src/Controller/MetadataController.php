<?php

namespace App\Controller;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Service\MetadataDumper;
use App\Service\MetadataWriter;
use App\Service\MusicScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tag editing, deletion and rescanning. Ported from the legacy
 * `edit_metadata`, `delete_song`, `full_scan`, `force_rescan` and
 * `rescan_covers` actions.
 *
 * `full_scan`/`force_rescan`/`rescan_covers` are unified into a single
 * "rescan library" admin action backed by `MusicScanner`, which already
 * re-extracts cover art on every processed file — see the `[x] FEATURE 3`
 * note in docs/PLAN_MIGRATION.md for the exact mapping.
 */
class MetadataController extends AbstractController
{
    private const ALLOWED_COVER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    #[Route('/song/{id}/edit', name: 'app_metadata_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        int $id,
        #[CurrentUser]
        User $user,
        MusicRepository $musicRepository,
        MetadataDumper $dumper,
    ): Response {
        $song = $this->findEditableSong($id, $user, $musicRepository);

        return $this->render('metadata/edit.html.twig', [
            'song' => $song,
            'rawMetadata' => $dumper->sections((string) $song->getFile()),
        ]);
    }

    #[Route('/song/{id}/edit', name: 'app_metadata_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function save(
        int $id,
        Request $request,
        #[CurrentUser]
        User $user,
        MusicRepository $musicRepository,
        MetadataWriter $writer,
        EntityManagerInterface $em,
    ): Response {
        $song = $this->findEditableSong($id, $user, $musicRepository);

        if (!$this->isCsrfTokenValid('metadata_edit_' . $song->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $title = trim((string) $request->request->get('title', ''));
        $artist = trim((string) $request->request->get('artist', ''));
        $album = trim((string) $request->request->get('album', ''));
        $genre = trim((string) $request->request->get('genre', ''));
        $lyrics = trim((string) $request->request->get('lyrics', ''));
        $isPrivate = (bool) $request->request->get('is_private', false);

        $song->setTitle($title);
        $song->setArtist($artist);
        $song->setAlbum($album);
        $song->setGenre($genre);
        $song->setLyrics($lyrics !== '' ? $lyrics : null);
        $song->setIsPrivate($isPrivate);

        $jpegCover = null;

        /** @var UploadedFile|null $cover */
        $cover = $request->files->get('cover_image');
        if ($cover !== null && $cover->isValid()) {
            // Sniff the real content type via ext-fileinfo rather than
            // UploadedFile::getMimeType(), which requires symfony/mime (not a
            // dependency of this project) and would throw at runtime.
            $mime = $this->sniffMimeType($cover->getPathname());
            if ($mime === null || !\in_array($mime, self::ALLOWED_COVER_MIME_TYPES, true)) {
                $this->addFlash('error', 'Invalid cover image type. Only JPG, PNG, WEBP, and GIF are allowed.');

                return $this->redirectToRoute('app_metadata_edit', ['id' => $song->getId()]);
            }

            $raw = file_get_contents($cover->getPathname());
            if ($raw === false) {
                $this->addFlash('error', 'Could not read the uploaded cover image.');

                return $this->redirectToRoute('app_metadata_edit', ['id' => $song->getId()]);
            }

            $webp = $this->processImage($raw, 'webp');
            $jpegCover = $this->processImage($raw, 'jpeg');
            if ($webp !== null) {
                $song->setImage($webp);
            }
        }

        // Write the tags into the physical file, then persist the DB row.
        $writer->write($song, $title, $artist, $album, $genre, $lyrics, $jpegCover);

        if (is_file((string) $song->getFile())) {
            clearstatcache(true, (string) $song->getFile());
            $song->setLastModified(filemtime((string) $song->getFile()) ?: $song->getLastModified());
        }

        $em->flush();

        $this->addFlash('success', 'Metadata updated successfully.');

        return $this->redirectToRoute('app_song', ['id' => $song->getId()]);
    }

    #[Route('/song/{id}/delete', name: 'app_metadata_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int $id,
        Request $request,
        #[CurrentUser]
        User $user,
        MusicRepository $musicRepository,
        EntityManagerInterface $em,
    ): Response {
        $song = $this->findEditableSong($id, $user, $musicRepository);

        if (!$this->isCsrfTokenValid('metadata_delete_' . $song->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = (string) $song->getFile();

        $em->remove($song);
        $em->flush();

        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }

        $this->addFlash('success', 'Song deleted.');

        return $this->redirectToRoute('app_library');
    }

    #[Route('/admin/rescan', name: 'app_admin_rescan', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rescan(Request $request, MusicScanner $scanner): Response
    {
        if (!$this->isCsrfTokenValid('admin_rescan', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $force = (bool) $request->request->get('force', false);

        try {
            $stats = $scanner->scan(null, $force);
            $this->addFlash('success', \sprintf(
                'Rescan complete — %d added, %d updated, %d removed, %d unchanged.',
                $stats['added'],
                $stats['updated'],
                $stats['removed'],
                $stats['skipped'],
            ));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_library');
    }

    /**
     * Loads a song and asserts the current user may edit/delete it (owner or
     * admin), mirroring the legacy `$song['user_id'] == $user_id || $is_admin
     * || $is_super_admin` checks scattered across `edit_metadata`/`delete_song`.
     */
    private function findEditableSong(int $id, User $user, MusicRepository $musicRepository): Music
    {
        $song = $musicRepository->find($id);
        if ($song === null) {
            throw $this->createNotFoundException('Track not found.');
        }

        if ($song->getOwner() !== $user && !$user->isSuperAdmin() && !\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('You do not have permission to edit this song.');
        }

        return $song;
    }

    /**
     * Resizes+center-crops an image to a square, mirroring the legacy
     * `process_image_to_webp`/`process_image_to_jpeg` helpers.
     */
    private function processImage(string $imageData, string $format, int $targetWidth = 640, int $quality = 82): ?string
    {
        $source = @imagecreatefromstring($imageData);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $minDim = min($srcW, $srcH);
        $srcX = (int) (($srcW - $minDim) / 2);
        $srcY = (int) (($srcH - $minDim) / 2);
        $finalSize = min($minDim, $targetWidth);

        $resized = imagecreatetruecolor(max(1, $finalSize), max(1, $finalSize));
        if ($format === 'webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $source, 0, 0, $srcX, $srcY, $finalSize, $finalSize, $minDim, $minDim);

        ob_start();
        if ($format === 'webp') {
            imagewebp($resized, null, $quality);
        } else {
            imagejpeg($resized, null, $quality);
        }
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        return $data !== false && $data !== '' ? $data : null;
    }

    /**
     * Sniffs the real MIME type from the file's magic bytes via ext-fileinfo.
     */
    private function sniffMimeType(string $path): ?string
    {
        $finfo = finfo_open(\FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }
}
