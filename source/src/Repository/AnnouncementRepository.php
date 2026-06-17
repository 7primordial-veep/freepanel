<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Announcement;
use App\Entity\User;
class AnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Announcement::class);
    }
    public function findLatestUnreadAnnouncement(User $user)
    {
        try {
            $queryBuilder = $this->createQueryBuilder("a");
            $queryBuilder->select("a");
            $queryBuilder->where("a.user = :user");
            $queryBuilder->AndWhere("a.isRead = :isRead");
            $queryBuilder->setParameter("user", $user);
            $queryBuilder->setParameter("isRead", false);
            $queryBuilder->orderBy("a.createdAt", "DESC");
            $queryBuilder->setMaxResults(1);
            $query = $queryBuilder->getQuery();
            $latestUnreadAnnouncement = $query->getSingleResult();
        } catch (\Exception $e) {
            $latestUnreadAnnouncement = null;
        }
        return $latestUnreadAnnouncement;
    }
}