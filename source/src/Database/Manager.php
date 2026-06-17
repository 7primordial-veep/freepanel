<?php

namespace App\Database;

use App\Database\Connection as DatabaseConnection;
use App\Entity\DatabaseServer as DatabaseServerEntity;
use App\Entity\DatabaseUser as DatabaseUserEntity;
use App\Entity\Database as DatabaseEntity;
use App\System\CommandExecutor;
use App\System\Command\DeleteDirectoryCommand;
class Manager
{
    private DatabaseConnection $databaseConnection;
    private DatabaseServerEntity $databaseServerEntity;
    public function __construct(DatabaseServerEntity $databaseServerEntity)
    {
        $this->databaseServerEntity = $databaseServerEntity;
        $this->databaseConnection = new DatabaseConnection($databaseServerEntity);
    }
    public function createDatabase(DatabaseEntity $databaseEntity) : void
    {
        $this->databaseConnection->createDatabase($databaseEntity);
    }
    public function deleteDatabase(DatabaseEntity $databaseEntity, $withUsers = true) : void
    {
        $this->databaseConnection->deleteDatabase($databaseEntity);
        if (true === $withUsers) {
            $databaseUsers = $databaseEntity->getUsers();
            foreach ($databaseUsers as $databaseUser) {
                $this->databaseConnection->deleteUser($databaseUser);
            }
        }
        $this->deleteDatabaseBackups($databaseEntity);
    }
    public function createUser(DatabaseUserEntity $databaseUserEntity) : void
    {
        $this->databaseConnection->createUser($databaseUserEntity);
    }
    public function deleteUser(DatabaseUserEntity $databaseUserEntity) : void
    {
        $this->databaseConnection->deleteUser($databaseUserEntity);
    }
    private function deleteDatabaseBackups(DatabaseEntity $databaseEntity)
    {
        $siteEntity = $databaseEntity->getSite();
        $siteUser = $siteEntity->getUser();
        $databaseName = $databaseEntity->getName();
        $databaseBackupDirectory = sprintf("/home/%s/backups/databases/%s/", $siteUser, $databaseName);
        $deleteDatabaseBackupDirectoryCommand = new DeleteDirectoryCommand();
        $deleteDatabaseBackupDirectoryCommand->setDirectory($databaseBackupDirectory);
        $commandExecutor = new CommandExecutor();
        $commandExecutor->execute($deleteDatabaseBackupDirectoryCommand);
    }
}