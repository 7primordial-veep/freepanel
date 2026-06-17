<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
class AppGetConfigValueCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("app:get:config-value");
        $this->setDescription("clpctl app:get:config-value 'cloud'");
        $this->addArgument("key", InputArgument::REQUIRED, "Key");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $key = trim($input->getArgument("key"));
            $configValue = $this->getConfigValue($key);
            $configValue = false === is_null($configValue) ? trim($configValue) : '';
            echo $configValue;
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>An error has occurred: \"%s\"</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}