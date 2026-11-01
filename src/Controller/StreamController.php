<?php

namespace App\Controller;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Service\CoverCache;
use App\Service\MediaResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Serves audio streams and cover art. The audio endpoint reproduces the legacy
 * `?action=get_stream` behaviour, including HTTP range support (206 Partial
 * Content) required for seeking and offline caching — but delegates the range
 * handling to Symfony's BinaryFileResponse instead of the hand-rolled fread
 * loop of the original. The response building lives in MediaResponder,
 * shared with the Subsonic API.
 */
class StreamController extends AbstractController
{
    public function __construct(private readonly MediaResponder $media) {}

    #[Route('/stream/{id}', name: 'app_stream', requirements: ['id' => '\d+'])]
    public function streamAudio(int $id, Request $request, MusicRepository $music, #[CurrentUser] ?User $user): Response
    {
        $song = $music->find($id);
        if ($song === null) {
            throw $this->createNotFoundException('Track not found.');
        }

        $this->assertVisible($song, $user);

        // `?dl=1` forces a download with a friendly "Artist - Title.ext" name
        // (ports the legacy `download_song` action). Otherwise the file streams
        // inline for the audio element (with Range/206 for seeking).
        return $this->media->audio($song, $request, $request->query->getBoolean('dl'));
    }

    #[Route('/cover/{id}', name: 'app_cover', requirements: ['id' => '\d+'])]
    public function cover(int $id, Request $request, MusicRepository $music, CoverCache $covers, #[CurrentUser] ?User $user): Response
    {
        $song = $music->find($id);
        if ($song === null || !$song->hasImage()) {
            return $this->media->placeholderCover();
        }

        $this->assertVisible($song, $user);

        $path = $covers->file($song);
        if ($path === null) {
            return $this->media->placeholderCover();
        }

        return $this->media->coverFile($path, (string) $song->getCoverHash(), $request);
    }

    /**
     * First view of a cached cover: the web server serves the files of
     * public/images/cover_cache/ itself and only a missing one reaches this
     * route (same path), which writes it from the audio file. The hash cannot
     * be guessed, so private tracks need no check here.
     */
    #[Route('/images/cover_cache/{hash}.webp', name: 'app_cover_cache', requirements: ['hash' => '[0-9a-f]{40}'])]
    public function cachedCover(string $hash, Request $request, MusicRepository $music, CoverCache $covers): Response
    {
        $song = $music->findOneBy(['coverHash' => $hash]);
        $path = $song !== null ? $covers->file($song) : null;
        if ($path === null) {
            return $this->media->placeholderCover();
        }

        return $this->media->coverFile($path, $hash, $request, 31536000, true);
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
