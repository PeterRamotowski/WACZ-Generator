<?php

namespace App\Command;

use App\Entity\WaczRequest;
use App\Service\MessengerQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'wacz:monitor',
    description: 'Monitor WACZ processing system health and show detailed status'
)]
class WaczMonitorCommand extends Command
{
    public function __construct(
        private readonly MessengerQueueService $queueService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'watch',
                'w',
                InputOption::VALUE_NONE,
                'Watch mode - refresh every 5 seconds'
            )
            ->addOption(
                'stuck-threshold',
                's',
                InputOption::VALUE_OPTIONAL,
                'Threshold in minutes to consider requests stuck', 
                30
            )
            ->setHelp(
                'This command provides comprehensive monitoring of the WACZ processing system, ' .
                'including queue status, active requests, and potential issues.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watch = $input->getOption('watch');
        $stuckThreshold = (int) $input->getOption('stuck-threshold');

        do {
            if ($watch) {
                $output->write("\033[2J\033[H"); // Clear screen
            }

            $this->showSystemStatus($io, $stuckThreshold);

            if ($watch) {
                $io->note('Refreshing in 5 seconds... (Press Ctrl+C to stop)');
                sleep(5);
            }
        } while ($watch);

        return Command::SUCCESS;
    }

    private function showSystemStatus(SymfonyStyle $io, int $stuckThreshold): void
    {
        $io->title('WACZ Processing System Monitor');
        $io->text('Timestamp: ' . (new \DateTime())->format('Y-m-d H:i:s'));

        $io->section('Queue Statistics');
        $queueStats = $this->queueService->getQueueStatistics();
        $workersActive = $this->queueService->areWorkersActive();

        $io->definitionList(
            ['Total Messages' => $queueStats['total_messages']],
            ['Pending Messages' => $queueStats['pending_messages']],
            ['Processed Messages' => $queueStats['processed_messages']],
            ['Failed Messages' => $queueStats['failed_messages']],
            ['Workers Active' => $workersActive ? '✅ Yes' : '❌ No']
        );

        $io->section('WACZ Request Status');
        $requestStats = $this->getRequestStatistics();

        $io->definitionList(
            ['Total Requests' => $requestStats['total']],
            ['Pending' => $requestStats['pending']],
            ['Processing' => $requestStats['processing']],
            ['Completed' => $requestStats['completed']],
            ['Failed' => $requestStats['failed']]
        );

        $activeRequests = $this->getActiveRequests();
        if (!empty($activeRequests)) {
            $io->section('Currently Processing');
            $table = [];
            foreach ($activeRequests as $request) {
                $duration = $this->getTimeDiff($request->getStartedAt());
                $table[] = [
                    $request->getId(),
                    $request->getUrl(),
                    $request->getStartedAt()->format('H:i:s'),
                    $duration
                ];
            }
            $io->table(['ID', 'URL', 'Started', 'Duration'], $table);
        }

        $stuckRequests = $this->getStuckRequests($stuckThreshold);
        if (!empty($stuckRequests)) {
            $io->section('⚠️  Stuck Requests (Potential Issues)');
            $io->warning(sprintf('Found %d request(s) stuck for more than %d minutes:', count($stuckRequests), $stuckThreshold));

            $table = [];
            foreach ($stuckRequests as $request) {
                $duration = $this->getTimeDiff($request->getStartedAt());
                $table[] = [
                    $request->getId(),
                    $request->getUrl(),
                    $request->getStartedAt() ? $request->getStartedAt()->format('H:i:s') : 'Unknown',
                    $duration
                ];
            }
            $io->table(['ID', 'URL', 'Started', 'Duration'], $table);

            $io->note('To reset stuck requests, run: php bin/console wacz:reset-stuck');
        }

        $io->section('System Health');
        $health = $this->assessSystemHealth($queueStats, $workersActive, count($stuckRequests));

        if ($health['status'] === 'healthy') {
            $io->success('✅ System is healthy');
        } elseif ($health['status'] === 'warning') {
            $io->warning('⚠️  System has warnings');
        } else {
            $io->error('❌ System has issues');
        }

        foreach ($health['messages'] as $message) {
            $io->text('• ' . $message);
        }
    }

    private function getRequestStatistics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $results = $qb
            ->select('wr.status, COUNT(wr.id) as count')
            ->from(WaczRequest::class, 'wr')
            ->groupBy('wr.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];

        foreach ($results as $result) {
            $status = $result['status'];
            $count = (int) $result['count'];
            $stats['total'] += $count;
            
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
        }

        return $stats;
    }
    
    private function getActiveRequests(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        return $qb
            ->select('wr')
            ->from(WaczRequest::class, 'wr')
            ->where('wr.status = :status')
            ->andWhere('wr.startedAt IS NOT NULL')
            ->setParameter('status', WaczRequest::STATUS_PROCESSING)
            ->orderBy('wr.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    private function getStuckRequests(int $thresholdMinutes): array
    {
        $cutoffTime = new \DateTime("-{$thresholdMinutes} minutes");

        $qb = $this->entityManager->createQueryBuilder();
        return $qb
            ->select('wr')
            ->from(WaczRequest::class, 'wr')
            ->where('wr.status = :status')
            ->andWhere('wr.startedAt < :cutoff OR wr.startedAt IS NULL')
            ->setParameter('status', WaczRequest::STATUS_PROCESSING)
            ->setParameter('cutoff', $cutoffTime)
            ->getQuery()
            ->getResult();
    }
    
    private function getTimeDiff(?\DateTime $startTime): string
    {
        if (!$startTime) {
            return 'Unknown';
        }

        $now = new \DateTime();
        $diff = $now->diff($startTime);

        $parts = [];
        if ($diff->h > 0) $parts[] = $diff->h . 'h';
        if ($diff->i > 0) $parts[] = $diff->i . 'm';
        $parts[] = $diff->s . 's';

        return implode(' ', $parts);
    }

    private function assessSystemHealth(array $queueStats, bool $workersActive, int $stuckCount): array
    {
        $health = ['status' => 'healthy', 'messages' => []];

        // Check workers
        if (!$workersActive && $queueStats['pending_messages'] > 0) {
            $health['status'] = 'error';
            $health['messages'][] = 'No active workers but there are pending messages';
        } elseif (!$workersActive) {
            $health['status'] = 'warning';
            $health['messages'][] = 'No active workers detected';
        } else {
            $health['messages'][] = 'Workers are active and processing messages';
        }

        // Check pending queue
        if ($queueStats['pending_messages'] > 10) {
            $health['status'] = 'warning';
            $health['messages'][] = "High number of pending messages ({$queueStats['pending_messages']})";
        } elseif ($queueStats['pending_messages'] > 0) {
            $health['messages'][] = "Normal queue activity ({$queueStats['pending_messages']} pending)";
        } else {
            $health['messages'][] = 'No pending messages in queue';
        }

        // Check failed messages
        if ($queueStats['failed_messages'] > 5) {
            $health['status'] = 'warning';
            $health['messages'][] = "High number of failed messages ({$queueStats['failed_messages']})";
        } elseif ($queueStats['failed_messages'] > 0) {
            $health['messages'][] = "Some failed messages present ({$queueStats['failed_messages']})";
        }

        // Check stuck requests
        if ($stuckCount > 0) {
            $health['status'] = 'error';
            $health['messages'][] = "Found {$stuckCount} stuck request(s) that need attention";
        }

        return $health;
    }
}