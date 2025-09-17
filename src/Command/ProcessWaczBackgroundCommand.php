<?php

namespace App\Command;

use App\Service\Wacz\WaczGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-wacz',
    description: 'Process WACZ request in background',
)]
class ProcessWaczBackgroundCommand extends Command
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('request-id', InputArgument::REQUIRED, 'WACZ Request ID to process')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requestId = (int) $input->getArgument('request-id');

        $io->title('Processing WACZ Request in Background');
        $io->text(sprintf('Request ID: %d', $requestId));

        $waczRequest = $this->waczGeneratorService->getWaczRequestById($requestId);
        
        if (!$waczRequest) {
            $io->error(sprintf('WACZ request with ID %d not found', $requestId));
            return Command::FAILURE;
        }

        try {
            $success = $this->waczGeneratorService->processWaczRequest($waczRequest);
            
            if ($success) {
                $io->success('WACZ processing completed successfully');
                return Command::SUCCESS;
            } else {
                $io->error('WACZ processing failed');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error(sprintf('Error during processing: %s', $e->getMessage()));

            $this->logger->error('Background processing exception', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            try {
                $waczRequest->setStatus('failed');
                $waczRequest->setErrorMessage($e->getMessage());
                $this->entityManager->flush();
            } catch (\Exception $dbException) {
                $this->logger->error('Failed to update request status', [
                    'request_id' => $requestId,
                    'error' => $dbException->getMessage()
                ]);
            }
            
            return Command::FAILURE;
        }
    }
}
