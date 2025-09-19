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
    name: 'wacz:queue:manage',
    description: 'Manage WACZ processing queue (status, cleanup, etc.)'
)]
class WaczQueueManageCommand extends Command
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
                'status',
                's',
                InputOption::VALUE_NONE,
                'Show queue status and statistics'
            )
            ->addOption(
                'cleanup',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Cleanup old processed messages (specify days old, default: 7)',
                false
            )
            ->addOption(
                'reset-stuck',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Reset stuck processing requests (specify timeout in minutes, default: 30)', 
                false
            )
            ->addOption(
                'force-reset',
                null,
                InputOption::VALUE_NONE,
                'Force reset ALL processing requests regardless of timeout (use with --reset-stuck)'
            )
            ->setHelp(
                'This command helps manage the WACZ processing queue. ' .
                'Use --status to see current queue statistics, --cleanup to remove old processed messages, ' .
                'or --reset-stuck to reset stuck processing requests. Use --force-reset with --reset-stuck to reset ALL processing requests.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->showStatus($io);
        }

        if ($input->getOption('cleanup') !== false) {
            $days = (int) ($input->getOption('cleanup') ?: 7);
            return $this->cleanup($io, $days);
        }

        if ($input->getOption('reset-stuck') !== false) {
            $timeout = (int) ($input->getOption('reset-stuck') ?: 30);
            $forceReset = $input->getOption('force-reset');
            return $this->resetStuck($io, $timeout, $forceReset);
        }

        return $this->showStatus($io);
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $io->title('WACZ Queue Status');

        $statistics = $this->queueService->getQueueStatistics();
        $workersActive = $this->queueService->areWorkersActive();

        $io->definitionList(
            ['Total Messages' => $statistics['total_messages']],
            ['Pending Messages' => $statistics['pending_messages']],
            ['Processed Messages' => $statistics['processed_messages']],
            ['Failed Messages' => $statistics['failed_messages']],
            ['Workers Active' => $workersActive ? '✅ Yes' : '❌ No']
        );

        if ($statistics['pending_messages'] > 0) {
            $io->warning("There are {$statistics['pending_messages']} pending messages in the queue.");

            if (!$workersActive) {
                $io->error('No workers appear to be active! Start workers with: php bin/console messenger:consume wacz_processing');
            }
        } else {
            $io->success('No pending messages in the queue.');
        }

        if ($statistics['failed_messages'] > 0) {
            $io->warning("There are {$statistics['failed_messages']} failed messages. You may want to investigate and retry them.");
        }

        return Command::SUCCESS;
    }

    private function cleanup(SymfonyStyle $io, int $days): int
    {
        $io->title("Cleaning up messages older than {$days} days");

        $deletedCount = $this->queueService->purgeOldMessages('wacz_processing', $days);

        if ($deletedCount > 0) {
            $io->success("Cleaned up {$deletedCount} old processed messages.");
        } else {
            $io->info('No old messages found to clean up.');
        }

        return Command::SUCCESS;
    }
    
    private function resetStuck(SymfonyStyle $io, int $timeout, bool $forceReset = false): int
    {
        if ($forceReset) {
            $io->title("Force resetting ALL processing WACZ requests");
            $io->warning('FORCE mode enabled - ALL processing requests will be reset regardless of timeout');
        } else {
            $io->title("Resetting stuck WACZ requests (timeout: {$timeout} minutes)");
        }

        $cutoffTime = new \DateTime("-{$timeout} minutes");

        // Find stuck requests
        $qb = $this->entityManager->createQueryBuilder();
        $query = $qb
            ->select('wr')
            ->from(WaczRequest::class, 'wr')
            ->where('wr.status = :status')
            ->setParameter('status', WaczRequest::STATUS_PROCESSING);

        if (!$forceReset) {
            // Only apply timeout filter if not in force mode
            $query->andWhere('wr.startedAt < :cutoff OR wr.startedAt IS NULL')
                  ->setParameter('cutoff', $cutoffTime);
        }

        $stuckRequests = $query->getQuery()->getResult();

        if (empty($stuckRequests)) {
            if ($forceReset) {
                $io->success('No processing requests found to reset!');
            } else {
                $io->success('No stuck requests found!');
            }
            return Command::SUCCESS;
        }

        $resetCount = 0;
        foreach ($stuckRequests as $request) {
            try {
                $request->setStatus(WaczRequest::STATUS_PENDING);
                $request->setStartedAt(null);
                $request->setErrorMessage(null);
                $this->entityManager->flush();

                $resetCount++;
                $io->info("Reset request #{$request->getId()}: {$request->getUrl()}");
            } catch (\Exception $e) {
                $io->error("Failed to reset request #{$request->getId()}: " . $e->getMessage());
            }
        }

        if ($resetCount > 0) {
            $io->success("Successfully reset {$resetCount} stuck request(s) to pending status");
        } else {
            $io->error('No requests were reset');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}