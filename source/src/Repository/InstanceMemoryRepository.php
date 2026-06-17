<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\InstanceMemory;
class InstanceMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceMemory::class);
    }
    public function getAverageMemoryValue(\DateTimeInterface $startTime, \DateTimeInterface $endTime) : int
    {
        $averageMemoryValue = 0;
        $queryBuilder = $this->createQueryBuilder("m");
        $queryBuilder->select("avg(m.value)");
        $queryBuilder->where("m.createdAt between :startTime and :endTime");
        $queryBuilder->setParameter("startTime", $startTime);
        $queryBuilder->setParameter("endTime", $endTime);
        $query = $queryBuilder->getQuery();
        try {
            $averageMemoryValue = (int) $query->getSingleScalarResult();
        } catch (\Exception $e) {
            $averageMemoryValue = 0;
        }
        return $averageMemoryValue;
    }
}