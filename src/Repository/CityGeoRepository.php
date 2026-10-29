<?php

namespace App\Repository;

use App\Entity\CityGeo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CityGeo>
 */
class CityGeoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CityGeo::class);
    }

    /**
     * Every located city.
     *
     * @return array<string, array{float, float}> CityGeo::place() => [lat, lng]
     */
    public function coordinates(): array
    {
        $rows = $this->createQueryBuilder('g')
            ->select('g.place, g.lat, g.lng')
            ->where('g.lat IS NOT NULL AND g.lng IS NOT NULL')
            ->getQuery()->getArrayResult();
        $coordinates = [];
        foreach ($rows as $row) {
            $coordinates[$row['place']] = [(float) $row['lat'], (float) $row['lng']];
        }

        return $coordinates;
    }

    /** @return array<string, CityGeo> CityGeo::place() => row */
    public function byPlace(): array
    {
        $rows = [];
        foreach ($this->findAll() as $row) {
            $rows[$row->getPlace()] = $row;
        }

        return $rows;
    }
}
