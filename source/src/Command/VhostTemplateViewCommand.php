<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
class VhostTemplateViewCommand extends BaseCommand
{
    private VhostTemplateEntityManager $vhostTemplateEntityManager;
    public function __construct(VhostTemplateEntityManager $vhostTemplateEntityManager)
    {
        $this->vhostTemplateEntityManager = $vhostTemplateEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("vhost-template:view");
        $this->setDescription("clpctl vhost-template:view --name='My Application'");
        $this->addOption("name", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $name = trim($input->getOption("name"));
            $vhostTemplateEntity = $this->vhostTemplateEntityManager->findOneByName($name);
            if (!(false === is_null($vhostTemplateEntity))) {
                throw new \Exception(sprintf("Vhost Template \"%s\" does not exist.", $name));
            }
            $vhostTemplate = $vhostTemplateEntity->getTemplate();
            $output->writeln($vhostTemplate);
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
}