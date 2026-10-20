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
