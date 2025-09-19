<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'wacz:worker:start',
    description: 'Start WACZ processing worker to consume messages from the queue'
)]
class WaczWorkerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Number of messages to consume before stopping',
                null
            )
            ->addOption(
                'time-limit',
                't',
                InputOption::VALUE_OPTIONAL,
                'Time limit in seconds after which the worker should stop',
                null
            )
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Memory limit in bytes after which the worker should stop',
                '128M'
            )
            ->setHelp(
                'This command starts a worker that consumes WACZ processing messages from the queue. ' .
                'It will continue processing messages until stopped or limits are reached.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Starting WACZ Processing Worker');
        $io->info('Worker will consume messages from the wacz_processing queue...');

        $command = ['bin/console', 'messenger:consume', 'wacz_processing', '--verbose'];

        if ($limit = $input->getOption('limit')) {
            $command[] = '--limit=' . $limit;
        }

        if ($timeLimit = $input->getOption('time-limit')) {
            $command[] = '--time-limit=' . $timeLimit;
        }

        if ($memoryLimit = $input->getOption('memory-limit')) {
            $command[] = '--memory-limit=' . $memoryLimit;
        }

        $io->note('Starting messenger consumer with command: ' . implode(' ', $command));

        $process = proc_open(
            implode(' ', $command),
            [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR
            ],
            $pipes,
            getcwd()
        );

        if (is_resource($process)) {
            $exitCode = proc_close($process);
            
            if ($exitCode === 0) {
                $io->success('Worker completed successfully');
            } else {
                $io->error("Worker exited with code: $exitCode");
            }
            
            return $exitCode;
        }

        $io->error('Failed to start worker process');
        return Command::FAILURE;
    }
}