<?php

namespace App\Repository;

use App\Entity\ListenLater;
use App\Entity\Music;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListenLater>
 */
class ListenLaterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListenLater::class);
    }

    public function findOneForUser(User $user, Music $song): ?ListenLater
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }

    public function findOneForUserAndSongId(User $user, int $songId): ?ListenLater
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.song = :songId')
            ->setParameter('user', $user)
            ->setParameter('songId', $songId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ListenLater[] Ordered by manual sort order, matching the legacy
     *                        `manual_order` default sort of `get_listen_later`.
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[] Song ids bookmarked by the user (for quick UI state).
     */
    public function songIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.song) AS song_id')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $r): int => (int) $r['song_id'], $rows);
    }

    public function nextSortOrder(User $user): int
    {
        $max = $this->createQueryBuilder('l')
            ->select('MAX(l.sortOrder)')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }

    /**
     * Applies a new manual order. $orderedSongIds[$index] = songId, mirroring
     * the legacy `update_listen_later_order` action (array index = new
     * sort_order). Entries not owned by the user (or not bookmarked) are
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
        $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
