<?php

namespace App\Repository;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<CrawledPage>
 */
class CrawledPageRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($registry, CrawledPage::class);
    }

    public function save(CrawledPage $entity, bool $flush = false): void
    {
        $entityManager = $this->getEntityManager();
        
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->persist($entity);

        if ($flush && $entityManager->isOpen()) {
            $entityManager->flush();
        }
    }

    public function remove(CrawledPage $entity, bool $flush = false): void
    {
        $entityManager = $this->getEntityManager();

        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->remove($entity);

        if ($flush && $entityManager->isOpen()) {
            $entityManager->flush();
        }
    }

    /**
     * @return CrawledPage[]
     */
    public function findByWaczRequest(WaczRequest $waczRequest): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.waczRequest = :waczRequest')
            ->setParameter('waczRequest', $waczRequest)
            ->orderBy('c.depth', 'ASC')
            ->addOrderBy('c.crawledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CrawledPage[]
     */
    public function findSuccessfulPages(WaczRequest $waczRequest): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.waczRequest = :waczRequest')
            ->andWhere('c.status = :status')
            ->setParameter('waczRequest', $waczRequest)
            ->setParameter('status', CrawledPage::STATUS_SUCCESS)
            ->orderBy('c.depth', 'ASC')
            ->addOrderBy('c.crawledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CrawledPage[]
     */
    public function findErrorPages(WaczRequest $waczRequest): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.waczRequest = :waczRequest')
            ->andWhere('c.status = :status')
            ->setParameter('waczRequest', $waczRequest)
            ->setParameter('status', CrawledPage::STATUS_ERROR)
            ->orderBy('c.crawledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getStatistics(WaczRequest $waczRequest): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select([
                'c.status',
                'COUNT(c.id) as count',
                'AVG(c.responseTime) as avg_response_time',
                'SUM(c.contentLength) as total_size'
            ])
            ->andWhere('c.waczRequest = :waczRequest')
            ->setParameter('waczRequest', $waczRequest)
            ->groupBy('c.status');

        $results = $qb->getQuery()->getResult();
        
        $stats = [
            'total' => 0,
            'success' => 0,
            'error' => 0,
            'skipped' => 0,
            'avg_response_time' => 0,
            'total_size' => 0
        ];

        foreach ($results as $result) {
            $stats[$result['status']] = (int) $result['count'];
            $stats['total'] += (int) $result['count'];
            
            if ($result['avg_response_time']) {
                $stats['avg_response_time'] = (int) $result['avg_response_time'];
            }
            
            if ($result['total_size']) {
                $stats['total_size'] += (int) $result['total_size'];
            }
        }

        return $stats;
    }

    /**
     * @return CrawledPage[]
     */
    public function findPagesByDepth(WaczRequest $waczRequest, int $depth): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.waczRequest = :waczRequest')
            ->andWhere('c.depth = :depth')
            ->setParameter('waczRequest', $waczRequest)
            ->setParameter('depth', $depth)
            ->orderBy('c.crawledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPageByUrl(WaczRequest $waczRequest, string $url): ?CrawledPage
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.waczRequest = :waczRequest')
            ->andWhere('c.url = :url')
            ->setParameter('waczRequest', $waczRequest)
            ->setParameter('url', $url)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
