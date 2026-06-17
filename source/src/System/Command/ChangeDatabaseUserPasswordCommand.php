<?php

namespace App\System\Command;

use App\System\Command;
use App\Entity\DatabaseServer;
class ChangeDatabaseUserPasswordCommand extends Command
{
    const USER_NAME_ROOT = "root";
    private ?string $userName = null;
    private ?string $newPassword = null;
    private $alterUserCommandTmpFile = null;
    private $clientCredentialsTmpFile = null;
    private ?DatabaseServer $databaseServer = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $databaseServer = $this->getDatabaseServer();
            $databaseServerHost = $databaseServer->getHost();
            $databaseServerUserName = $databaseServer->getUserName();
            $databaseServerPassword = $databaseServer->getPassword();
            $databaseServerPort = $databaseServer->getPort();
            $newPassword = $this->getNewPassword();
            $userName = $this->getUserName();
            if (self::USER_NAME_ROOT == $userName) {
                $updateUserPasswordCommand = "ALTER USER '{{userName}}'@'127.0.0.1' IDENTIFIED BY '{{password}}';" . PHP_EOL;
            } else {
                $updateUserPasswordCommand = "ALTER USER '{{userName}}'@'%' IDENTIFIED BY '{{password}}';" . PHP_EOL;
            }
            $updateUserPasswordCommand .= "Flush Privileges;";
            $updateUserPasswordCommand = str_replace(["{{userName}}", "{{password}}"], [$userName, addslashes($newPassword)], $updateUserPasswordCommand);
            $clientCredentialsCommand = "[client]\nuser={{userName}}\npassword={{password}}\nhost={{host}}\nport={{port}}";
            $this->alterUserCommandTmpFile = tmpfile();
            $alterUserCommandTmpFile = stream_get_meta_data($this->alterUserCommandTmpFile)["uri"];
            file_put_contents($alterUserCommandTmpFile, $updateUserPasswordCommand);
            $clientCredentialsCommand = str_replace(["{{userName}}", "{{password}}", "{{host}}", "{{port}}"], [$databaseServerUserName, $databaseServerPassword, $databaseServerHost, $databaseServerPort], $clientCredentialsCommand);
            $this->clientCredentialsTmpFile = tmpfile();
            $clientCredentialsTmpFile = stream_get_meta_data($this->clientCredentialsTmpFile)["uri"];
            file_put_contents($clientCredentialsTmpFile, $clientCredentialsCommand);
            $this->command = sprintf("/usr/bin/sudo /usr/bin/mysql --defaults-extra-file=%s < %s", $clientCredentialsTmpFile, $alterUserCommandTmpFile);
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        return true;
    }
    public function setDatabaseServer(DatabaseServer $databaseServer)
    {
        $this->databaseServer = $databaseServer;
    }
    public function getDatabaseServer()
    {
        return $this->databaseServer;
    }
    public function setUserName($userName)
    {
        $this->userName = $userName;
    }
    public function getUserName()
    {
        return $this->userName;
    }
    public function setNewPassword($newPassword)
    {
        $this->newPassword = $newPassword;
    }
    public function getNewPassword()
    {
        return $this->newPassword;
    }
}