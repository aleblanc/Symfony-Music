<?php

namespace App\Service;

use App\Entity\History;
use App\Entity\Music;
use App\Entity\PlayCount;
use App\Entity\User;
use App\Repository\PlayCountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records one listen: bumps the per-user play count and appends a history
 * row. Shared by the web player (/play/{id}) and the Subsonic `scrobble`,
 * which may report listens made offline, hours earlier ($playedAt).
 * Does not flush.
 */
final class PlayRecorder
{
    public function __construct(
        private readonly PlayCountRepository $playCounts,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param bool $dedupe skip when a history row already exists for this
     *                     song at this exact second (a client re-sending a
     *                     scrobble it was not sure went through)
     *
     * @return bool false when skipped as a duplicate
     */
    public function record(User $user, Music $song, \DateTimeImmutable $playedAt, bool $dedupe = false): bool
    {
        $stamp = $playedAt->format('Y-m-d H:i:s');
        if ($dedupe && $this->em->getRepository(History::class)->findOneBy(['user' => $user, 'song' => $song, 'playedAt' => $stamp]) !== null) {
            return false;
        }

        $playCount = $this->playCounts->findOneForUser($user, $song);
        if ($playCount === null) {
            $playCount = new PlayCount();
            $playCount->setUser($user);
            $playCount->setSong($song);
            $this->em->persist($playCount);
            $playCount->touch($playedAt);
        } else {
            $playCount->increment();
            // An offline listen reported late must not move "last played" back.
            if ($playCount->getLastPlayed() === null || $playCount->getLastPlayed() < $stamp) {
                $playCount->touch($playedAt);
            }
        }

        $history = new History();
        $history->setUser($user);
        $history->setSong($song);
        $history->setPlayedAt($playedAt);
        $this->em->persist($history);

        return true;
    }
}
