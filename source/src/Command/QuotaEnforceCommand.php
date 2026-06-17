<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\SiteManager;
use App\Site\ResourceQuota\DiskQuotaProbe;

class QuotaEnforceCommand extends BaseCommand
{
    private SiteManager $siteManager;
    private DiskQuotaProbe $diskQuotaProbe;

    public function __construct(SiteManager $siteManager, DiskQuotaProbe $diskQuotaProbe)
    {
        parent::__construct();
        $this->siteManager = $siteManager;
        $this->diskQuotaProbe = $diskQuotaProbe;
    }

    protected function configure() : void
    {
        $this->setName('quota:enforce');
        $this->setDescription('Measure per-site disk usage and persist results. Warns when usage exceeds the configured quota.');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $sites = $this->siteManager->findAll();
        $checked = 0;
        $warned = 0;

        foreach ($sites as $site) {
            $user = $site->getUser();
            if (empty($user)) {
                continue;
            }

            try {
                $usedMb = $this->diskQuotaProbe->measure($user);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s: %s</error>', $site->getDomainName(), $e->getMessage()));
                continue;
            }

            $site->setLastDiskUsageMb($usedMb);
            $site->setLastDiskCheckAt(new \DateTime('now'));
            $this->siteManager->updateEntity($site);
            $checked++;

            $quota = $site->getDiskQuotaMb();
            if (null !== $quota && $quota > 0 && $usedMb > $quota) {
                $warned++;
                $output->writeln(sprintf('<comment>%s: %d MB used / %d MB quota</comment>', $site->getDomainName(), $usedMb, $quota));
            }
        }

        $output->writeln(sprintf('Checked %d sites, %d over quota.', $checked, $warned));

        return 0;
    }
}
