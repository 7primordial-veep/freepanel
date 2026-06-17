<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use App\Command\Command as BaseCommand;
class DatabaseShowMasterCredentialsCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("db:show:master-credentials");
        $this->setDescription("clpctl db:show:master-credentials");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $container = $this->getContainer();
            $databaseServerManager = $container->get("App\\Entity\\Manager\\DatabaseServerManager");
            $databaseServer = $databaseServerManager->getActiveDatabaseServer();
            if (false === is_null($databaseServer)) {
                $table = new Table($output);
                $table->setHeaders(["Name", "Value"]);
                $tableRows = [["Host", $databaseServer->getHost()], ["User Name", $databaseServer->getUserName()], ["Password", $databaseServer->getDecryptedPassword()], ["Port", $databaseServer->getPort()]];
                $table->setRows($tableRows);
                $table->render();
                $connectCommand = sprintf("mysql -h%s -P%s -u%s -p%s -A", escapeshellarg($databaseServer->getHost()), escapeshellarg($databaseServer->getPort()), escapeshellarg($databaseServer->getUserName()), escapeshellarg($databaseServer->getDecryptedPassword()));
                $output->writeln('');
                $output->writeln(sprintf("<info>Connect Command: %s</info>", $connectCommand));
                $output->writeln('');
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}