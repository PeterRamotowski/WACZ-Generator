<?php

namespace App\Command;

use App\Entity\WaczRequest;
use App\Message\ProcessWaczMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'wacz:restart-pending',
    description: 'Restart pending and stuck WACZ processing requests after Docker restart'
)]
class WaczRestartPendingProcessesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'include-stuck',
                's',
                InputOption::VALUE_NONE,
                'Also restart requests stuck in processing status (older than 30 minutes)'
            )
            ->addOption(
                'stuck-timeout',
                't',
                InputOption::VALUE_OPTIONAL,
                'Timeout in minutes for considering a request as stuck (default: 30)',
                30
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be restarted without actually doing it'
            )
            ->setHelp(
                'This command finds pending WACZ requests and dispatches them for processing. ' .
                'It can also identify and restart stuck processing requests that were interrupted ' .
                'during Docker restart or worker crashes. Use this after restarting Docker containers.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $includeStuck = $input->getOption('include-stuck');
        $stuckTimeout = (int) $input->getOption('stuck-timeout');
        $dryRun = $input->getOption('dry-run');

        $io->title('Restarting Pending WACZ Processes');

        if ($dryRun) {
            $io->warning('Running in DRY-RUN mode - no messages will be dispatched');
        }

        // Find pending requests
        $pendingRequests = $this->findPendingRequests();
        $stuckRequests = [];

        if ($includeStuck) {
            $stuckRequests = $this->findStuckRequests($stuckTimeout);
        }

        $totalRequests = count($pendingRequests) + count($stuckRequests);

        if ($totalRequests === 0) {
            $io->success('No pending or stuck requests found to restart!');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d pending and %d stuck requests to restart', 
            count($pendingRequests), 
            count($stuckRequests)
        ));

        // Display pending requests
        if (!empty($pendingRequests)) {
            $io->section('Pending Requests');
            $this->displayRequests($io, $pendingRequests, 'pending');
        }

        // Display stuck requests
        if (!empty($stuckRequests)) {
            $io->section('Stuck Requests (will be reset to pending)');
            $this->displayRequests($io, $stuckRequests, 'processing');
        }

        if ($dryRun) {
            $io->note('In dry-run mode - no actual processing would occur');
            return Command::SUCCESS;
        }

        // Confirm restart
        if (!$io->confirm(sprintf('Restart %d request(s)?', $totalRequests), false)) {
            $io->info('Operation cancelled');
            return Command::SUCCESS;
        }

        $restartedCount = 0;

        // Reset stuck requests first
        foreach ($stuckRequests as $request) {
            try {
                $request->setStatus(WaczRequest::STATUS_PENDING);
                $request->setStartedAt(null);
                $request->setErrorMessage(null);
                $this->entityManager->flush();

                $io->info(sprintf('Reset stuck request #%d to pending', $request->getId()));
            } catch (\Exception $e) {
                $io->error(sprintf('Failed to reset request #%d: %s', $request->getId(), $e->getMessage()));
                continue;
            }
        }

        // Dispatch messages for all pending requests (including newly reset ones)
        $allRequests = array_merge($pendingRequests, $stuckRequests);
        
        foreach ($allRequests as $request) {
            try {
                $message = new ProcessWaczMessage($request->getId());
                $this->messageBus->dispatch($message);
                
                $this->logger->info('Dispatched WACZ processing message after restart', [
                    'request_id' => $request->getId(),
                    'url' => $request->getUrl()
                ]);

                $restartedCount++;
                $io->info(sprintf('Dispatched processing for request #%d: %s', 
                    $request->getId(), 
                    $request->getUrl()
                ));

            } catch (\Exception $e) {
                $io->error(sprintf('Failed to dispatch request #%d: %s', $request->getId(), $e->getMessage()));
                
                $this->logger->error('Failed to dispatch WACZ processing message after restart', [
                    'request_id' => $request->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($restartedCount > 0) {
            $io->success(sprintf('Successfully restarted %d request(s)', $restartedCount));
            $io->note('Messages have been dispatched to the queue. Make sure workers are running:');
            $io->text('php bin/console messenger:consume wacz_processing');
        } else {
            $io->error('No requests were restarted');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function findPendingRequests(): array
    {
        return $this->entityManager
            ->getRepository(WaczRequest::class)
            ->findBy(['status' => WaczRequest::STATUS_PENDING]);
    }

    private function findStuckRequests(int $timeoutMinutes): array
    {
        $cutoffTime = new \DateTime("-{$timeoutMinutes} minutes");

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

    private function displayRequests(SymfonyStyle $io, array $requests, string $currentStatus): void
    {
        $table = [];
        foreach ($requests as $request) {
            $table[] = [
                $request->getId(),
                $request->getUrl(),
                $request->getTitle(),
                $currentStatus,
                $request->getStartedAt() ? $request->getStartedAt()->format('Y-m-d H:i:s') : 'Never',
                $request->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }

        $io->table(
            ['ID', 'URL', 'Title', 'Status', 'Started At', 'Created At'], 
            $table
        );
    }
}