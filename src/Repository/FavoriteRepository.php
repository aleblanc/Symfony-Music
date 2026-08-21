<?php

namespace App\Repository;

use App\Entity\Favorite;
use App\Entity\Music;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favorite>
 */
class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    public function findOneForUser(User $user, Music $song): ?Favorite
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }

    /**
     * @return Favorite[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[] Song ids favorited by the user (for quick UI state).
     */
    public function favoritedSongIds(User $user): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.song) AS song_id')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $r): int => (int) $r['song_id'], $rows);
    }

    /**
     * Most recently favorited tracks first, for the "Your Favorites" shelf in
     * `get_recommendations` (legacy: `ORDER BY sort_order DESC LIMIT 10`).
     *
     * @return Music[]
     */
    public function recentFavorites(User $user, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f', 'song')
            ->join('f.song', 'song')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.sortOrder', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // The inner join on f.song guarantees every row has a song.
        return array_map(static fn(Favorite $f): Music => $f->getSong() ?? throw new \LogicException('Favorite row must have a joined song.'), $rows);
    }
}
