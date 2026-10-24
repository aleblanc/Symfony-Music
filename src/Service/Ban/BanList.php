<?php

namespace App\Service\Ban;

use App\Entity\BannedAudio;
use App\Entity\Music;
use App\Repository\BannedAudioRepository;
use App\Service\Enrichment\Fingerprinter;
use App\Service\Enrichment\MatchScorer;
use App\Service\Enrichment\RawFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * "Not this song": a track whose audio is another recording than its title
 * says. Banning keeps its fingerprint for that artist and title, sets the
 * file aside in MUSIC_DIR/_banned (same relative path, not indexed) and
 * removes it from the library; whatever fetches tracks later asks
 * {@see self::match()} to refuse the same audio for that track.
 *
 * Fingerprints are compared loosely (another encode of the same recording
 * never gives the exact same one), only against the bans of the same track:
 * the recording may be a real song elsewhere.
 */
class BanList
{
    public const DIR = '_banned';
    /** Share of identical fingerprint bits from which it is the banned recording. */
    private const SAME_AUDIO = 0.75;
    /** Artist and title similarity from which a ban concerns the asked track. */
    private const SAME_TRACK = 0.8;

    public function __construct(
        private readonly BannedAudioRepository $bans,
        private readonly Fingerprinter $fingerprinter,
        private readonly MatchScorer $scorer,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $musicDir,
    ) {}

    public function isAvailable(): bool
    {
        return $this->fingerprinter->isAvailable();
    }

    /**
     * @return array{banned: BannedAudio, setAside: string, restored: ?string} restored: the file the track now plays, if a listener put one back
     *
     * @throws \RuntimeException when the file cannot be fingerprinted or moved (nothing changed then)
     */
    public function ban(Music $song): array
    {
        $file = (string) $song->getFile();
        if (!is_file($file)) {
            throw new \RuntimeException('file not found: ' . $file);
        }
        [$fingerprint, $duration] = $this->fingerprinter->raw($file);
        if ($fingerprint === []) {
            throw new \RuntimeException('empty fingerprint: ' . basename($file));
        }

        $root = rtrim($this->musicDir, '/');
        $relative = str_starts_with($file, $root . '/') ? substr($file, \strlen($root) + 1) : basename($file);
        $setAside = $root . '/' . self::DIR . '/' . $relative;
        for ($i = 2; file_exists($setAside); ++$i) {
            $setAside = $root . '/' . self::DIR . '/' . preg_replace('/(\.[^.\/]+)?$/', " ($i)$1", $relative, 1);
        }
        if (!is_dir(\dirname($setAside)) && !mkdir(\dirname($setAside), 0o777, true) && !is_dir(\dirname($setAside))) {
            throw new \RuntimeException('cannot create ' . \dirname($setAside));
        }
        if (!rename($file, $setAside)) {
            throw new \RuntimeException('cannot move the file to ' . $setAside);
        }

        $banned = new BannedAudio((string) $song->getArtist(), $song->getDisplayTitle(), $fingerprint, (int) round($duration), $file, new \DateTimeImmutable());
        $this->em->persist($banned);

        $event = $this->dispatcher->dispatch(new TrackBannedEvent($song, $file));
        if ($event->restoredFile !== null && is_file($event->restoredFile)) {
            // Re-read by the next app:scan (tags, format, bitrate of the restored file).
            $song->setFile($event->restoredFile)->setLastModified(null);
        } else {
            $this->em->remove($song);
        }
        $this->em->flush();

        return ['banned' => $banned, 'setAside' => $setAside, 'restored' => $event->restoredFile];
    }

    /**
     * The ban this file falls under when it is offered as $artist — $title,
     * null when it is not a banned recording of that track (or fpcalc is missing).
     */
    public function match(string $file, string $artist, string $title): ?BannedAudio
    {
        $bans = array_filter($this->bans->findAll(), fn(BannedAudio $b): bool => $this->scorer->similarity($title, $b->getTitle()) >= self::SAME_TRACK
            && $this->scorer->similarity($artist, $b->getArtist()) >= self::SAME_TRACK);
        if ($bans === [] || !$this->fingerprinter->isAvailable()) {
            return null;
        }
        [$fingerprint] = $this->fingerprinter->raw($file);
        foreach ($bans as $ban) {
            if (RawFingerprint::compare($ban->getFingerprint(), $fingerprint)['score'] >= self::SAME_AUDIO) {
                return $ban;
            }
        }

        return null;
    }
}
