<?php

namespace App\Repository;

use App\Entity\History;
use App\Entity\Music;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<History>
 */
class HistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, History::class);
    }

    /**
     * Recently played tracks (most recent first), de-duplicated by song.
     *
     * @return Music[]
     */
    public function recentSongs(User $user, int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('h', 'song')
            ->join('h.song', 'song')
            ->andWhere('h.user = :user')
            ->setParameter('user', $user)
            ->orderBy('h.playedAt', 'DESC')
            ->setMaxResults($limit * 4)
            ->getQuery()
            ->getResult();

        $seen = [];
        $songs = [];
        foreach ($rows as $row) {
            $song = $row->getSong();
            $id = $song->getId();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $songs[] = $song;
            if (\count($songs) >= $limit) {
                break;
            }
        }

        return $songs;
    }

    /**
     * @return int[] Distinct song ids the user has ever listened to, used to
     *               exclude already-heard tracks from "discover" shelves.
     */
    public function songIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('DISTINCT IDENTITY(h.song) AS song_id')
            ->andWhere('h.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['song_id'], $rows);
    }
}
