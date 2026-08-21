<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\RhythmFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RhythmFavorite>
 */
class RhythmFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RhythmFavorite::class);
    }

    public function findOneForUser(User $user, Music $song): ?RhythmFavorite
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }

    /**
     * @return RhythmFavorite[] ordered by manual sort order (legacy default)
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('rf')
            ->andWhere('rf.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rf.sortOrder', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[] song ids the user favorited for the rhythm game
     */
    public function songIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('rf')
            ->select('IDENTITY(rf.song) AS song_id')
            ->andWhere('rf.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rf.sortOrder', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['song_id'], $rows);
    }

    public function nextSortOrder(User $user): int
    {
        $max = $this->createQueryBuilder('rf')
            ->select('MAX(rf.sortOrder)')
            ->andWhere('rf.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
