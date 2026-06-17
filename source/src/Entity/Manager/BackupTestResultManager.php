<?php

namespace App\Entity\Manager;

use App\Entity\BackupTestResult;

class BackupTestResultManager extends BaseManager
{
    public function findLatest(int $limit = 10): array
    {
        return $this->repository->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    public function findLatestOne(): ?BackupTestResult
    {
        $rows = $this->findLatest(1);
        return $rows[0] ?? null;
    }

    /**
     * Pick the next site to test, round-robin by oldest test (or never tested).
     */
    public function findNextDomainToTest(array $candidateDomains): ?string
    {
        if (0 === count($candidateDomains)) {
            return null;
        }
        $tested = [];
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('b.domainName, MAX(b.createdAt) AS lastAt')
            ->from(BackupTestResult::class, 'b')
            ->groupBy('b.domainName');
        foreach ($qb->getQuery()->getResult() as $row) {
            $tested[$row['domainName']] = $row['lastAt'];
        }
        // domains never tested win first
        foreach ($candidateDomains as $d) {
            if (!isset($tested[$d])) {
                return $d;
            }
        }
        // otherwise the one tested longest ago among candidates
        $best = null;
        $bestAt = null;
        foreach ($candidateDomains as $d) {
            $at = $tested[$d] ?? null;
            if (null === $bestAt || ($at instanceof \DateTime && $at < $bestAt)) {
                $best = $d;
                $bestAt = $at;
            }
        }
        return $best;
    }
}
