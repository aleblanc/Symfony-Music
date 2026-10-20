<?php

namespace App\Repository;

use App\Entity\ImportItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportItem>
 */
class ImportItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportItem::class);
    }

    public function findOneByPath(string $path): ?ImportItem
    {
        return $this->findOneBy(['path' => $path]);
    }

    /** The pending file after $afterId (by path order), wrapping around; null when none is left. */
    public function findNextPending(?ImportItem $after = null): ?ImportItem
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->setParameter('status', ImportItem::PENDING)
            ->orderBy('i.path', 'ASC')
            ->setMaxResults(1);
        if ($after !== null) {
            $next = (clone $qb)->andWhere('i.path > :path')->setParameter('path', $after->getPath())->getQuery()->getOneOrNullResult();
            if ($next instanceof ImportItem) {
                return $next;
            }
            $qb->andWhere('i.id != :id')->setParameter('id', $after->getId());
        }
        $item = $qb->getQuery()->getOneOrNullResult();

        return $item instanceof ImportItem ? $item : null;
    }

    /**
     * @return array<string, int> status => count
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys([ImportItem::PENDING, ImportItem::UNMATCHED, ImportItem::FAILED, ImportItem::SKIPPED, ImportItem::IMPORTED, ImportItem::DUPLICATE], 0);
        foreach ($this->createQueryBuilder('i')->select('i.status AS status, COUNT(i.id) AS n')->groupBy('i.status')->getQuery()->getArrayResult() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Files waiting for a choice, oldest attempt first.
     *
     * @return list<ImportItem>
     */
    public function findPending(int $limit = 200): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->setParameter('status', ImportItem::PENDING)
            ->orderBy('i.path', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
