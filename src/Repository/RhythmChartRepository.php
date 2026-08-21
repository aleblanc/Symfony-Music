<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\RhythmChart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RhythmChart>
 */
class RhythmChartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RhythmChart::class);
    }

    public function findOneForSong(Music $song, string $difficulty): ?RhythmChart
    {
        return $this->findOneBy(['song' => $song, 'difficulty' => $difficulty]);
    }

    /**
     * @return array<string, int> difficulty => level, for one song
     */
    public function levelsForSong(int $songId): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.difficulty AS difficulty', 'c.level AS level')
            ->andWhere('IDENTITY(c.song) = :songId')
            ->setParameter('songId', $songId)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['difficulty']] = (int) $r['level'];
        }

        return $out;
    }

    /**
     * @param int[] $songIds
     *
     * @return array<int, array<string, int>> songId => (difficulty => level)
     */
    public function levelsForSongs(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.song) AS song_id', 'c.difficulty AS difficulty', 'c.level AS level')
            ->andWhere('IDENTITY(c.song) IN (:ids)')
            ->setParameter('ids', $songIds)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['song_id']][(string) $r['difficulty']] = (int) $r['level'];
        }

        return $out;
    }
}
