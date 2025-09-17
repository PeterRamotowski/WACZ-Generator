<?php

namespace App\Command;

use App\Service\WaczGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'wacz:process',
    description: 'Process WACZ generation request in background'
)]
class ProcessWaczCommand extends Command
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
            ->addArgument('id', InputArgument::REQUIRED, 'WACZ request ID to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('id');

        $io->title("Processing WACZ Request #{$id}");

        $waczRequest = $this->waczGeneratorService->getWaczRequestById($id);

        if (!$waczRequest) {
            $io->error("WACZ request with ID {$id} not found.");
            return Command::FAILURE;
        }

        if ($waczRequest->getStatus() !== 'pending') {
            $io->warning("WACZ request #{$id} is not in pending status (current: {$waczRequest->getStatus()}).");
            return Command::FAILURE;
        }

        $io->info("Starting processing for URL: {$waczRequest->getUrl()}");

        try {
            $success = $this->waczGeneratorService->processWaczRequest($waczRequest);

            if ($success) {
                $io->success("✅ WACZ processing completed successfully!");
                $io->info("File: {$waczRequest->getFilePath()}");
                $io->info("Size: " . ($waczRequest->getFileSize() ? number_format($waczRequest->getFileSize() / 1024 / 1024, 2) . ' MB' : 'Unknown'));
                return Command::SUCCESS;
            } else {
                $io->error("❌ WACZ processing failed");
                if ($waczRequest->getErrorMessage()) {
                    $io->error("Error: {$waczRequest->getErrorMessage()}");
                }
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error("❌ Critical error during processing: {$e->getMessage()}");
            $waczRequest->setStatus('failed');
            $waczRequest->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
            return Command::FAILURE;
        }
    }
}
