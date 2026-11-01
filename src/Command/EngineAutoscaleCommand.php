<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EngineScaling\EngineAutoscaler;
use App\Service\EngineScaling\QueueDepth;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:engine:autoscale', 'Scale the LibreTranslate app to 0 when idle, or up/down by hand')]
final class EngineAutoscaleCommand extends Command
{
    public function __construct(
        private readonly EngineAutoscaler $autoscaler,
        private readonly QueueDepth $queue,
        private readonly \App\Service\EngineScaling\EngineScalerInterface $scaler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('scale', null, InputOption::VALUE_REQUIRED, 'Force a replica count (0|1), bypassing the idle check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null !== ($n = $input->getOption('scale'))) {
            $this->scaler->scaleTo((int) $n);
            $io->success("Scale to $n requested.");

            return Command::SUCCESS;
        }

        $depth = $this->queue->get();
        if ($depth === null) {
            $io->warning('Queue depth unknown; leaving the engine as it is.');

            return Command::SUCCESS;
        }
        $io->writeln($this->autoscaler->scaleDownIfIdle($depth) ? 'Scaled down.' : "Nothing to do (queue depth $depth).");

        return Command::SUCCESS;
    }
}
