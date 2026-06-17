<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
class AppSetConfigValueCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("app:set:config-value");
        $this->setDescription("clpctl app:set:config-value 'key' 'value'");
        $this->addArgument("key", InputArgument::REQUIRED, "Key");
        $this->addArgument("value", InputArgument::REQUIRED, "Value");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $key = trim($input->getArgument("key"));
            $value = trim($input->getArgument("value"));
            if (false === empty($key)) {
                $configManager = $this->getConfigManager();
                $configManager->set($key, $value);
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>An error has occurred: \"%s\"</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}