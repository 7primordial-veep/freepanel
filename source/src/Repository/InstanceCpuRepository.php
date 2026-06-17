<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\InstanceCpu;
class InstanceCpuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceCpu::class);
    }
    public function getAverageCpuValue(\DateTimeInterface $startTime, \DateTimeInterface $endTime) : int
    {
        $averageCpuValue = 0;
        $queryBuilder = $this->createQueryBuilder("c");
        $queryBuilder->select("avg(c.value)");
        $queryBuilder->where("c.createdAt between :startTime and :endTime");
        $queryBuilder->setParameter("startTime", $startTime);
        $queryBuilder->setParameter("endTime", $endTime);
        $query = $queryBuilder->getQuery();
        try {
            $averageCpuValue = (int) $query->getSingleScalarResult();
        } catch (\Exception $e) {
            $averageCpuValue = 0;
        }
        return $averageCpuValue;
    }
}