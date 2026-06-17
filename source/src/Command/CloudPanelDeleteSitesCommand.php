<?php

namespace App\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\SiteManager as SiteEntityManager;
class CloudPanelDeleteSitesCommand extends BaseCommand
{
    private SiteEntityManager $siteEntityManager;
    public function __construct(SiteEntityManager $siteEntityManager)
    {
        $this->siteEntityManager = $siteEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("cloudpanel:delete:sites");
        $this->setDescription("cloudpanel:delete:sites");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $sites = $this->siteEntityManager->findAll();
            if (count($sites)) {
                $application = $this->getApplication();
                $siteDeleteCommand = $application->find("site:delete");
                foreach ($sites as $site) {
                    try {
                        $domainName = $site->getDomainName();
                        $arguments = ["--domainName" => $domainName, "--force" => true];
                        $inputData = new ArrayInput($arguments);
                        $returnCode = $siteDeleteCommand->run($inputData, $output);
                    } catch (\Exception $e) {
                    }
                }
            }
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
}