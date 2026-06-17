<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\MarketplaceApp;

class MarketplaceAppRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceApp::class);
    }

    public function findOneBySlug(string $slug): ?MarketplaceApp
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
