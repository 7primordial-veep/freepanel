<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\InstanceLoadAverage;
class InstanceLoadAverageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceLoadAverage::class);
    }
    public function getLoadAverageValue($period, \DateTimeInterface $startTime, \DateTimeInterface $endTime) : mixed
    {
        $loadAverageValue = 0;
        $queryBuilder = $this->createQueryBuilder("l");
        $queryBuilder->select("avg(l.value)");
        $queryBuilder->where("l.period = :period");
        $queryBuilder->AndWhere("l.createdAt between :startTime and :endTime");
        $queryBuilder->setParameter("period", $period);
        $queryBuilder->setParameter("startTime", $startTime);
        $queryBuilder->setParameter("endTime", $endTime);
        $query = $queryBuilder->getQuery();
        try {
            $loadAverageValue = $query->getSingleScalarResult();
        } catch (\Exception $e) {
            $loadAverageValue = 0;
        }
        return $loadAverageValue;
    }
}