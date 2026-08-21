<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\OfflineSong;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OfflineSong>
 */
class OfflineSongRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OfflineSong::class);
    }

    public function findOneForUser(User $user, Music $song): ?OfflineSong
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }

    public function findOneForUserAndSongId(User $user, int $songId): ?OfflineSong
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.user = :user')
            ->andWhere('o.song = :songId')
            ->setParameter('user', $user)
            ->setParameter('songId', $songId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return OfflineSong[] Ordered by manual sort order, matching the legacy
     *                        `manual_order` default sort of `get_offline_songs`.
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[] Song ids marked offline by the user (for quick UI state).
     */
    public function songIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.song) AS song_id')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['song_id'], $rows);
    }

    public function nextSortOrder(User $user): int
    {
        $max = $this->createQueryBuilder('o')
            ->select('MAX(o.sortOrder)')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }

    /**
     * Applies a new manual order. $orderedSongIds[$index] = songId, mirroring
     * the legacy `update_offline_order` action (array index = new
     * sort_order). Entries not owned by the user (or not marked offline) are
     * silently skipped, same as the legacy UPDATE ... WHERE user_id = ?.
     *
     * @param int[] $orderedSongIds
     */
    public function updateOrder(User $user, array $orderedSongIds): void
    {
        foreach ($orderedSongIds as $index => $songId) {
            $entry = $this->findOneForUserAndSongId($user, (int) $songId);
            if ($entry !== null) {
                $entry->setSortOrder((int) $index);
            }
        }

        $this->getEntityManager()->flush();
    }

    public function clearForUser(User $user): void
    {
        $this->createQueryBuilder('o')
            ->delete()
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
