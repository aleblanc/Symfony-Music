<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\PlayCount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayCount>
 */
class PlayCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayCount::class);
    }

    public function findOneForUser(User $user, Music $song): ?PlayCount
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }

    /**
     * Top played tracks for a user, used by the "For You" shelf.
     *
     * @return Music[]
     */
    public function topSongs(User $user, int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('pc')
            ->select('pc', 'song')
            ->join('pc.song', 'song')
            ->andWhere('pc.user = :user')
            ->setParameter('user', $user)
            ->orderBy('pc.playCount', 'DESC')
            ->addOrderBy('pc.lastPlayed', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // The inner join on pc.song guarantees every row has a song.
        return array_map(static fn(PlayCount $pc): Music => $pc->getSong() ?? throw new \LogicException('Play count row must have a joined song.'), $rows);
    }

    /**
     * The user's single most-played artist (by summed play count), used by
     * the "More from <artist>" shelf in `get_recommendations`.
     */
    public function topArtist(User $user): ?string
    {
        $result = $this->createQueryBuilder('pc')
            ->select('song.artist AS artist')
            ->join('pc.song', 'song')
            ->andWhere('pc.user = :user')
            ->andWhere("song.artist != 'Unknown Artist'")
            ->setParameter('user', $user)
            ->groupBy('song.artist')
            ->orderBy('SUM(pc.playCount)', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['artist'] ?? null;
    }

    /**
     * The user's top genres (by summed play count), used by the "Your Genre
     * Mix" shelf in `get_recommendations`.
     *
     * @return string[]
     */
    public function topGenres(User $user, int $limit = 2): array
    {
        $rows = $this->createQueryBuilder('pc')
            ->select('song.genre AS genre')
            ->join('pc.song', 'song')
            ->andWhere('pc.user = :user')
            ->andWhere("song.genre != ''")
            ->andWhere('song.genre IS NOT NULL')
            ->andWhere("song.genre != 'Unknown Genre'")
            ->setParameter('user', $user)
            ->groupBy('song.genre')
            ->orderBy('SUM(pc.playCount)', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $r): string => $r['genre'], $rows);
    }
}
