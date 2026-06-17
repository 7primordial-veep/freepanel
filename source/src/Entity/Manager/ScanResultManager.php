<?php

namespace App\Entity\Manager;

class ScanResultManager extends BaseManager
{
    public function findRecent(int $limit = 50): array
    {
        return $this->repository->findRecent($limit);
    }
}
