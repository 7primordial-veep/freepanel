<?php

namespace App\Database;

use App\Entity\Database as DatabaseEntity;
use App\System\CommandExecutor;
use App\System\Command\ImportDatabaseDumpCommand;
class Importer
{
    private DatabaseEntity $databaseEntity;
    private CommandExecutor $commandExecutor;
    private ?string $runAsUser = null;
    public function __construct(DatabaseEntity $databaseEntity)
    {
        $this->databaseEntity = $databaseEntity;
        $this->commandExecutor = new CommandExecutor();
    }
    public function setRunAsUser(string $userName) : void
    {
        $this->runAsUser = $userName;
    }
    public function getRunAsUser() : ?string
    {
        return $this->runAsUser;
    }
    private function addImportDatabaseDumpCommand(string $importFile)
    {
        $importDatabaseDumpCommand = new ImportDatabaseDumpCommand();
        $importDatabaseDumpCommand->setDatabaseEntity($this->databaseEntity);
        $importDatabaseDumpCommand->setFile($importFile);
        $this->commandExecutor->execute($importDatabaseDumpCommand, 7200);
    }
    public function import(string $importFile) : void
    {
        $this->addImportDatabaseDumpCommand($importFile);
    }
}