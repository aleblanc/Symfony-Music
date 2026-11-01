<?php

namespace App\Twig;

use App\Entity\Music;
use App\Service\CoverCache;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * cover_url(track): the static file of the cover (served by the web server)
 * when the track's cover hash is known, else /cover/{id}, which serves it too.
 * Takes a Music entity, a row with `id` (+ `coverHash`) or a bare track id.
 */
class CoverExtension
{
    public function __construct(
        private readonly CoverCache $covers,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    /** @param Music|array{id?: int|string, coverHash?: ?string}|object|int|string|null $track */
    #[AsTwigFunction('cover_url')]
    public function coverUrl(mixed $track): string
    {
        [$id, $hash] = match (true) {
            $track instanceof Music => [$track->getId(), $track->getCoverHash()],
            \is_array($track) => [$track['id'] ?? null, $track['coverHash'] ?? null],
            \is_object($track) && method_exists($track, 'getId') => [$track->getId(), null],
            default => [$track, null],
        };
        if (\is_string($hash) && $hash !== '') {
            return $this->covers->url($hash);
        }

        return $this->urls->generate('app_cover', ['id' => is_numeric($id) ? (int) $id : 0]);
    }
}
