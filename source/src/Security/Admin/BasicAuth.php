<?php

namespace App\Security\Admin;

use App\System\CommandExecutor;
use App\System\Command\WriteFileCommand;
use App\System\Command\DeleteFileCommand;
use App\System\Command\ChownCommand;
class BasicAuth
{
    private const CREDENTIALS_FILE = "/home/clp/services/nginx/basic-auth/credentials";
    private CommandExecutor $commandExecutor;
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function isEnabled() : bool
    {
        $isEnabled = file_exists(self::CREDENTIALS_FILE);
        return $isEnabled;
    }
    public function enable($userName, $password) : void
    {
        $credentialsContent = sprintf("%s:%s", $userName, password_hash($password, PASSWORD_BCRYPT));
        $writeFileCommand = new WriteFileCommand();
        $writeFileCommand->setFile(self::CREDENTIALS_FILE);
        $writeFileCommand->setContent($credentialsContent);
        $chownFileCommand = new ChownCommand();
        $chownFileCommand->setUser("clp");
        $chownFileCommand->setGroup("clp");
        $chownFileCommand->setFile(self::CREDENTIALS_FILE);
        $this->commandExecutor->execute($writeFileCommand);
        $this->commandExecutor->execute($chownFileCommand);
    }
    public function disable() : void
    {
        $deleteFileCommand = new DeleteFileCommand();
        $deleteFileCommand->setFile(self::CREDENTIALS_FILE);
        $this->commandExecutor->execute($deleteFileCommand);
    }
}