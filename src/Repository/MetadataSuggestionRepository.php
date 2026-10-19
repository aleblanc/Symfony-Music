<?php

namespace App\Repository;

use App\Entity\MetadataSuggestion;
use App\Entity\Music;
use App\Enum\SuggestionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetadataSuggestion>
 */
class MetadataSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetadataSuggestion::class);
    }

    public function save(MetadataSuggestion $suggestion, bool $flush = true): void
    {
        $this->getEntityManager()->persist($suggestion);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Suggestions with the given status, grouped by release so a whole album
     * reads together, best scores first.
     *
     * @return list<MetadataSuggestion>
     */
    public function findByStatus(SuggestionStatus $status, int $limit = 200): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('m')
            ->join('s.music', 'm')
            ->where('s.status = :status')
            ->setParameter('status', $status)
            ->orderBy('s.releaseId', 'ASC')
            ->addOrderBy('s.score', 'DESC')
            ->addOrderBy('m.discNumber', 'ASC')
            ->addOrderBy('m.file', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MetadataSuggestion>
     */
    public function findPendingForRelease(string $releaseId): array
    {
        return $this->findBy(['status' => SuggestionStatus::Pending, 'releaseId' => $releaseId]);
    }

    /** Drops older pending suggestions for a track before a new one is recorded. */
    public function removePendingForMusic(Music $music): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.music = :music')
            ->andWhere('s.status = :status')
            ->setParameter('music', $music)
            ->setParameter('status', SuggestionStatus::Pending)
            ->getQuery()
            ->execute();
    }

    /**
     * @return array<string, int> status value => count
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->groupBy('s.status')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(array_map(static fn(SuggestionStatus $s): string => $s->value, SuggestionStatus::cases()), 0);
        foreach ($rows as $row) {
            $status = $row['status'] instanceof SuggestionStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }
}
