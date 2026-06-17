<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\User;
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }
    public function countAll()
    {
        try {
            $queryBuilder = $this->createQueryBuilder("u");
            $queryBuilder->select("COUNT(u.id)");
            $query = $queryBuilder->getQuery();
            $numberOfUsers = (int) $query->getSingleScalarResult();
        } catch (\Exception $e) {
            $numberOfUsers = 0;
        }
        return $numberOfUsers;
    }
}