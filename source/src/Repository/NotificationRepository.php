<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Notification;
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }
    public function getNumberOfUnreadNotifications()
    {
        $queryBuilder = $this->createQueryBuilder("n");
        $queryBuilder->select("COUNT(n.id) as number_of_unread");
        $queryBuilder->where("n.isRead = :isRead");
        $queryBuilder->setParameter("isRead", false);
        $query = $queryBuilder->getQuery();
        try {
            $numberOfUnreadNotifications = (int) $query->getSingleScalarResult();
        } catch (\Exception $e) {
            $numberOfUnreadNotifications = 0;
        }
        return $numberOfUnreadNotifications;
    }
}