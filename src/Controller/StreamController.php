<?php

namespace App\Controller;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\MusicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Serves audio streams and cover art. The audio endpoint reproduces the legacy
 * `?action=get_stream` behaviour, including HTTP range support (206 Partial
 * Content) required for seeking and offline caching — but delegates the range
 * handling to Symfony's BinaryFileResponse instead of the hand-rolled fread
 * loop of the original.
 */
class StreamController extends AbstractController
{
    private const MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'flac' => 'audio/flac',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
    ];

    #[Route('/stream/{id}', name: 'app_stream', requirements: ['id' => '\d+'])]
    public function streamAudio(int $id, Request $request, MusicRepository $music, #[CurrentUser] ?User $user): Response
    {
        $song = $music->find($id);
        if ($song === null) {
            throw $this->createNotFoundException('Track not found.');
        }

        $this->assertVisible($song, $user);

        $path = (string) $song->getFile();
        if (!is_file($path)) {
            throw $this->createNotFoundException('Audio file missing on disk.');
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', self::MIME_TYPES[$ext] ?? 'application/octet-stream');
        $response->headers->set('Accept-Ranges', 'bytes');

        // `?dl=1` forces a download with a friendly "Artist - Title.ext" name
        // (ports the legacy `download_song` action). Otherwise the file streams
        // inline for the audio element (with Range/206 for seeking).
        if ($request->query->getBoolean('dl')) {
            $niceName = trim(\sprintf('%s - %s', (string) $song->getArtist(), (string) $song->getDisplayTitle()), ' -');
            // Content-Disposition filenames may not contain "/" or "\" (and we
            // strip other path-hostile chars). Symfony also derives an ASCII
            // fallback for the header automatically.
            $niceName = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#', ' ', $niceName) ?? '';
            $niceName = trim($niceName) !== '' ? trim($niceName) : 'track';
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $niceName . '.' . $ext);
        }

        // BinaryFileResponse parses the Range header and emits 206 automatically.
        $response->prepare($request);

        return $response;
    }

    #[Route('/cover/{id}', name: 'app_cover', requirements: ['id' => '\d+'])]
    public function cover(int $id, MusicRepository $music, #[CurrentUser] ?User $user): Response
    {
        $song = $music->find($id);
        if ($song === null || !$song->hasImage()) {
            return $this->placeholderCover();
        }

        $this->assertVisible($song, $user);

        $image = $song->getImage();
        $bytes = \is_resource($image) ? stream_get_contents($image) : (string) $image;

        $response = new Response($bytes);
        $response->headers->set('Content-Type', $this->sniffImageMime($bytes));
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }

    /**
     * Sniffs the image MIME from the leading magic bytes (the shared schema
     * stores no mime column). Falls back to jpeg.
     */
    private function sniffImageMime(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP') => 'image/webp',
            default => 'image/jpeg',
        };
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

    /**
     * A neutral SVG placeholder for tracks without embedded artwork.
     */
    private function placeholderCover(): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
            . '<rect width="200" height="200" fill="#1e1e24"/>'
            . '<path d="M78 60v58a20 20 0 1 0 8 16V86l44-12v34a20 20 0 1 0 8 16V52z" fill="#4a4a55"/>'
            . '</svg>';

        $response = new Response($svg);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->setPublic();
        $response->setMaxAge(604800);

        return $response;
    }
}
