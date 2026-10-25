<?php

namespace App\Service;

use App\Entity\Music;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the HTTP responses for audio files and cover images, shared by the
 * web player (StreamController) and the Subsonic API. Range requests (206)
 * are handled by BinaryFileResponse, which is what makes seeking and resumed
 * downloads work.
 */
final class MediaResponder
{
    private const AUDIO_MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'flac' => 'audio/flac',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
    ];

    /**
     * @param bool $download force an attachment named "Artist - Title.ext"
     */
    public function audio(Music $song, Request $request, bool $download = false): BinaryFileResponse
    {
        $path = (string) $song->getFile();
        if (!is_file($path)) {
            throw new NotFoundHttpException('Audio file missing on disk.');
        }

        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', self::AUDIO_MIME_TYPES[$ext] ?? 'application/octet-stream');
        $response->headers->set('Accept-Ranges', 'bytes');

        if ($download) {
            $niceName = trim(\sprintf('%s - %s', (string) $song->getArtist(), $song->getDisplayTitle()), ' -');
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

    /**
     * Raw image bytes as a cacheable response, with an ETag so clients can
     * revalidate cheaply (304).
     */
    public function image(string $bytes, Request $request, int $maxAge = 86400): Response
    {
        $response = new Response();
        $response->setEtag(md5($bytes));
        $response->setPublic();
        $response->setMaxAge($maxAge);
        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($bytes);
        $response->headers->set('Content-Type', self::sniffImageMime($bytes));

        return $response;
    }

    /**
     * A neutral SVG placeholder for tracks without embedded artwork.
     */
    public function placeholderCover(): Response
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

    /**
     * Sniffs the image MIME from the leading magic bytes (the shared schema
     * stores no mime column). Falls back to jpeg.
     */
    public static function sniffImageMime(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP') => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
