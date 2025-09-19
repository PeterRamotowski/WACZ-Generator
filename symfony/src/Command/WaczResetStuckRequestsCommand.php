<?php

namespace App\Command;

use App\Entity\WaczRequest;
use App\Service\Wacz\WaczGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'wacz:reset-stuck',
    description: 'Reset stuck WACZ processing requests that have been processing for too long'
)]
class WaczResetStuckRequestsCommand extends Command
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'timeout',
                't',
                InputOption::VALUE_OPTIONAL,
                'Timeout in minutes after which a processing request is considered stuck',
                30
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be reset without actually doing it'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force reset all processing requests regardless of timeout'
            )
            ->setHelp(
                'This command finds WACZ requests that have been in "processing" status for longer than the timeout ' .
                'and resets them to "pending" so they can be processed again. This is useful when workers die unexpectedly. ' .
                'Use --force to reset ALL processing requests regardless of timeout.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = (int) $input->getOption('timeout');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->title("Resetting Stuck WACZ Requests");

        if ($dryRun) {
            $io->warning('Running in DRY-RUN mode - no changes will be made');
        }

        if ($force) {
            $io->warning('FORCE mode enabled - ALL processing requests will be reset regardless of timeout');
            $cutoffTime = new \DateTime();
            $io->info("Force resetting ALL processing requests");
        } else {
            $cutoffTime = new \DateTime("-{$timeout} minutes");
            $io->info("Looking for requests stuck since before: " . $cutoffTime->format('Y-m-d H:i:s'));
        }

        // Find stuck requests
        $qb = $this->entityManager->createQueryBuilder();
        $query = $qb
            ->select('wr')
            ->from(WaczRequest::class, 'wr')
            ->where('wr.status = :status')
            ->setParameter('status', WaczRequest::STATUS_PROCESSING);

        if (!$force) {
            // Only apply timeout filter if not in force mode
            $query->andWhere('wr.startedAt < :cutoff OR wr.startedAt IS NULL')
                  ->setParameter('cutoff', $cutoffTime);
        }

        $stuckRequests = $query->getQuery()->getResult();

        if (empty($stuckRequests)) {
            if ($force) {
                $io->success('No processing requests found to reset!');
            } else {
                $io->success('No stuck requests found!');
            }
            return Command::SUCCESS;
        }

        if ($force) {
            $io->warning(sprintf('Found %d processing request(s) to force reset:', count($stuckRequests)));
        } else {
            $io->warning(sprintf('Found %d stuck request(s):', count($stuckRequests)));
        }

        $table = [];
        foreach ($stuckRequests as $request) {
            $table[] = [
                $request->getId(),
                $request->getUrl(),
                $request->getStartedAt() ? $request->getStartedAt()->format('Y-m-d H:i:s') : 'Unknown',
                $request->getStartedAt() ? $this->getTimeDiff($request->getStartedAt()) : 'Unknown'
            ];
        }

        $io->table(['ID', 'URL', 'Started At', 'Duration'], $table);

        if ($dryRun) {
            if ($force) {
                $io->info('DRY-RUN: These processing requests would be force reset to pending status');
            } else {
                $io->info('DRY-RUN: These requests would be reset to pending status');
            }
            return Command::SUCCESS;
        }

        $confirmMessage = $force 
            ? 'Force reset ALL these processing requests to pending status?' 
            : 'Reset these requests to pending status?';

        if (!$io->confirm($confirmMessage, false)) {
            $io->info('Operation cancelled');
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
            $io->note('These requests can now be processed by workers again');
        } else {
            $io->error('No requests were reset');
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }

    private function getTimeDiff(\DateTime $startTime): string
    {
        $now = new \DateTime();
        $diff = $now->diff($startTime);

        $parts = [];
        if ($diff->h > 0) $parts[] = $diff->h . 'h';
        if ($diff->i > 0) $parts[] = $diff->i . 'm';
        $parts[] = $diff->s . 's';

        return implode(' ', $parts);
    }
}