<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use App\Command\SiteCommand as SiteCommand;
class TestCommand extends SiteCommand
{
    protected function configure() : void
    {
        $this->setName("test:test");
        $this->setDescription("clpctl test:test");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $logger = $this->getLogger();
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $logger->exception($e);
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>An error has occurred: \"%s\"</error>", $errorMessage));
            return Command::FAILURE;
        }
    }
}