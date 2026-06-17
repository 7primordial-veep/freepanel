<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Entity\Manager\MarketplaceAppManager;

class MarketplaceSeedCommand extends Command
{
    private MarketplaceAppManager $marketplaceAppManager;

    public function __construct(MarketplaceAppManager $marketplaceAppManager)
    {
        parent::__construct();
        $this->marketplaceAppManager = $marketplaceAppManager;
    }

    protected function configure(): void
    {
        $this->setName('marketplace:seed')
            ->setDescription('Seed the marketplace catalog with default apps (idempotent).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->marketplaceAppManager->seedDefaults();
            $output->writeln('<info>Marketplace catalog seeded.</info>');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }
    }
}
