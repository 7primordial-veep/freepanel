<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Query;
use App\Entity\VhostTemplate;
class VhostTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VhostTemplate::class);
    }
    public function deleteTemplatesByType($type) : Query
    {
        $queryBuilder = $this->createQueryBuilder("vt");
        $queryBuilder->delete();
        $queryBuilder->where("vt.type = :type");
        $queryBuilder->setParameter("type", $type);
        $query = $queryBuilder->getQuery();
        $query->execute();
        return $query;
    }
}