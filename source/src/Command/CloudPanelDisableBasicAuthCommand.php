<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Security\Admin\BasicAuth as AdminBasicAuth;
class CloudPanelDisableBasicAuthCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("cloudpanel:disable:basic-auth");
        $this->setDescription("clpctl cloudpanel:disable:basic-auth");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $adminBasicAuth = new AdminBasicAuth();
            $adminBasicAuth->disable();
            $output->writeln("<info>Basic Auth has been disabled.</info>");
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}