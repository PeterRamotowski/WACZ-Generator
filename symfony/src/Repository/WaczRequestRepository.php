<?php

namespace App\Repository;

use App\Entity\WaczRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<WaczRequest>
 */
class WaczRequestRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($registry, WaczRequest::class);
    }

    public function save(WaczRequest $entity, bool $flush = false): void
    {
        $entityManager = $this->getEntityManager();
        
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->persist($entity);

        if ($flush) {
            $entityManager->flush();
        }
    }

    public function remove(WaczRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return WaczRequest[]
     */
    public function findPendingRequests(?int $limit = 50): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', WaczRequest::STATUS_PENDING)
            ->orderBy('w.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WaczRequest[]
     */
    public function findCompletedRequests(?int $limit = 50): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', WaczRequest::STATUS_COMPLETED)
            ->orderBy('w.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WaczRequest[]
     */
    public function findFailedRequests(?int $limit = 50): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', WaczRequest::STATUS_FAILED)
            ->orderBy('w.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WaczRequest[]
     */
    public function findRecentRequests(?int $limit = 10): array
    {
        return $this->createQueryBuilder('w')
            ->orderBy('w.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOldCompletedRequests(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->andWhere('w.completedAt < :before')
            ->setParameter('status', WaczRequest::STATUS_COMPLETED)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('w')
            ->select([
                'w.status',
                'COUNT(w.id) as count'
            ])
            ->groupBy('w.status');

        $results = $qb->getQuery()->getResult();

        $durationQb = $this->createQueryBuilder('w')
            ->select('AVG(TIMESTAMPDIFF(SECOND, w.startedAt, w.completedAt)) as avg_duration')
            ->where('w.startedAt IS NOT NULL')
            ->andWhere('w.completedAt IS NOT NULL');
        
        $durationResult = $durationQb->getQuery()->getSingleScalarResult();

        $stats = [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'avg_duration' => $durationResult ? (int) $durationResult : 0
        ];

        foreach ($results as $result) {
            $stats[$result['status']] = (int) $result['count'];
            $stats['total'] += (int) $result['count'];
        }

        return $stats;
    }
}
