<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\InstanceDiskUsage;
class InstanceDiskUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceDiskUsage::class);
    }
    public function getAverageDiskSizeValue($disk, \DateTimeInterface $startTime, \DateTimeInterface $endTime) : int
    {
        $averageDiskSizeValue = 0;
        $queryBuilder = $this->createQueryBuilder("d");
        $queryBuilder->select("avg(d.value)");
        $queryBuilder->where("d.disk = :disk");
        $queryBuilder->AndWhere("d.createdAt between :startTime and :endTime");
        $queryBuilder->setParameter("disk", $disk);
        $queryBuilder->setParameter("startTime", $startTime);
        $queryBuilder->setParameter("endTime", $endTime);
        $query = $queryBuilder->getQuery();
        try {
            $averageDiskSizeValue = (int) $query->getSingleScalarResult();
        } catch (\Exception $e) {
            $averageDiskSizeValue = 0;
        }
        return $averageDiskSizeValue;
    }
}