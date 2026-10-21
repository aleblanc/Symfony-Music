<?php

namespace App\Repository;

use App\Entity\AlbumFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlbumFavorite>
 */
class AlbumFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlbumFavorite::class);
    }

    public function findOneForUser(User $user, string $releaseGroupId): ?AlbumFavorite
    {
        return $this->findOneBy(['user' => $user, 'releaseGroupId' => $releaseGroupId]);
    }

    /** @return list<AlbumFavorite> latest first */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<string> release group ids the user hearted */
    public function releaseGroupIdsForUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_column($this->createQueryBuilder('f')
            ->select('f.releaseGroupId')
            ->andWhere('f.user = :user')->setParameter('user', $user)
            ->getQuery()->getScalarResult(), 'releaseGroupId');
    }
}
