<?php

namespace App\Repository;

use App\Entity\RhythmScore;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RhythmScore>
 */
class RhythmScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RhythmScore::class);
    }

    /**
     * Global leaderboard: total score per player, paginated. Ported from the
     * legacy `get_rhythm_leaderboard` aggregate.
     *
     * @return list<array<string, mixed>>
     */
    public function globalLeaderboard(int $page, int $pageSize): array
    {
        return array_values($this->createQueryBuilder('rs')
            ->select(
                'IDENTITY(rs.user) AS user_id',
                'u.artist AS player_name',
                'SUM(rs.score) AS total_score',
                'COUNT(rs.id) AS total_plays',
                'SUM(rs.perfect) AS total_perfect',
                'SUM(rs.great) AS total_great',
                'SUM(rs.good) AS total_good',
                'SUM(rs.bad) AS total_bad',
                'SUM(rs.miss) AS total_miss',
            )
            ->join('rs.user', 'u')
            ->groupBy('rs.user')
            ->addGroupBy('u.artist')
            ->orderBy('total_score', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getScalarResult());
    }

    /**
     * Per-song leaderboard (top 25 runs). Ported from
     * `get_song_rhythm_leaderboard`.
     *
     * @return list<array<string, mixed>>
     */
    public function songLeaderboard(int $songId): array
    {
        return array_values($this->createQueryBuilder('rs')
            ->select(
                'rs.score AS score',
                'rs.maxCombo AS max_combo',
                'rs.perfect AS perfect',
                'rs.great AS great',
                'rs.good AS good',
                'rs.bad AS bad',
                'rs.miss AS miss',
                'u.artist AS player_name',
                'u.id AS user_id',
            )
            ->join('rs.user', 'u')
            ->andWhere('IDENTITY(rs.song) = :songId')
            ->setParameter('songId', $songId)
            ->orderBy('rs.score', 'DESC')
            ->addOrderBy('rs.maxCombo', 'DESC')
            ->addOrderBy('rs.perfect', 'DESC')
            ->setMaxResults(25)
            ->getQuery()
            ->getScalarResult());
    }

    /**
     * A player's runs, newest first. Ported from `get_user_rhythm_scores`.
     *
     * @return RhythmScore[]
     */
    public function forUser(User $user, ?int $page = null, int $pageSize = 25): array
    {
        $qb = $this->createQueryBuilder('rs')
            ->andWhere('rs.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rs.playedAt', 'DESC');

        if ($page !== null) {
            $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize);
        }

        return $qb->getQuery()->getResult();
    }
}
