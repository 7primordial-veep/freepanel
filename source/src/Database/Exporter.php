<?php

namespace App\Database;

use App\Entity\Database as DatabaseEntity;
use App\System\CommandExecutor;
use App\System\Command\CreateDirectoryCommand;
use App\System\Command\CreateDatabaseDumpCommand;
class Exporter
{
    private DatabaseEntity $databaseEntity;
    private CommandExecutor $commandExecutor;
    private ?string $file = null;
    private ?string $runAsUser = null;
    public function __construct(DatabaseEntity $databaseEntity)
    {
        $this->databaseEntity = $databaseEntity;
        $this->commandExecutor = new CommandExecutor();
    }
    public function setFile(string $file) : void
    {
        $this->file = $file;
    }
    public function getFile() : ?string
    {
        return $this->file;
    }
    public function setRunAsUser(string $userName) : void
    {
        $this->runAsUser = $userName;
    }
    public function getRunAsUser() : ?string
    {
        return $this->runAsUser;
    }
    public function createOutputDirectory()
    {
        $file = $this->getFile();
        $outputDirectory = sprintf("%s/", dirname($file));
        $createOutputDirectoryCommand = new CreateDirectoryCommand();
        $createOutputDirectoryCommand->setDirectory($outputDirectory);
        $this->commandExecutor->execute($createOutputDirectoryCommand);
    }
    private function addCreateDatabaseDumpCommand()
    {
        $file = $this->getFile();
        $createDatabaseDumpCommand = new CreateDatabaseDumpCommand();
        if (false === is_null($this->runAsUser)) {
            $createDatabaseDumpCommand->setRunAsUser($this->runAsUser);
        }
        $createDatabaseDumpCommand->setDatabaseEntity($this->databaseEntity);
        $createDatabaseDumpCommand->setFile($file);
        $this->commandExecutor->execute($createDatabaseDumpCommand, 7200);
    }
    public function export() : void
    {
        $this->addCreateDatabaseDumpCommand();
    }
}