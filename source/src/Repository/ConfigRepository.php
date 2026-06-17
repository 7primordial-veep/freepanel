<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Config;
class ConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }
    public function deleteByWildcard(string $wildcard) : Query
    {
        $queryBuilder = $this->createQueryBuilder("c");
        $queryBuilder->delete();
        $queryBuilder->where("c.key like :wildcard");
        $queryBuilder->setParameter("wildcard", $wildcard);
        $query = $queryBuilder->getQuery();
        $query->execute();
        return $query;
    }
}