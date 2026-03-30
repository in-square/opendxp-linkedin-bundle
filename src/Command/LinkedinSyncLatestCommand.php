<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Command;

use InSquare\OpendxpProcessManagerBundle\ExecutionTrait;
use InSquare\OpendxpLinkedinBundle\Service\LinkedinSettings;
use InSquare\OpendxpLinkedinBundle\Service\LinkedinPostSyncService;
use OpenDxp\Console\AbstractCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class LinkedinSyncLatestCommand extends AbstractCommand
{
    use ExecutionTrait;

    public function __construct(
        private readonly LinkedinPostSyncService $syncService,
        private readonly LinkedinSettings $settings,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:linkedin:sync-latest')
            ->setDescription('Sync latest LinkedIn posts')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of latest posts', $this->settings->getItemsLimit())
            ->addOption('monitoring-item-id', null, InputOption::VALUE_REQUIRED, 'Contains the monitoring item if executed via the OpenDXP backend');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        if ($limit <= 0) {
            $limit = 3;
        }

        try {
            $monitoringId = $input->getOption('monitoring-item-id');
            if ($monitoringId) {
                static::initProcessManager((int) $monitoringId);
            }
            $counts = $this->syncService->syncLatest($limit);
        } catch (\Throwable $exception) {
            $this->logger->error('LinkedIn sync failed: ' . $exception->getMessage());
            $io->error('LinkedIn sync failed. Check logs.');
            return self::FAILURE;
        }

        $io->success(sprintf(
            'LinkedIn sync finished. Added: %d, Updated: %d, Skipped: %d',
            $counts['added'],
            $counts['updated'],
            $counts['skipped']
        ));

        return self::SUCCESS;
    }
}
