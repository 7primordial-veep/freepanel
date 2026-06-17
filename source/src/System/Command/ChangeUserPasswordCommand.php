<?php

namespace App\System\Command;

use App\System\Command;
class ChangeUserPasswordCommand extends Command
{
    private ?string $userName = null;
    private ?string $password = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $userName = $this->getUserName();
            $password = $this->getPassword();
            if (preg_match("/\\r\\n|\\r|\\n/", $password)) {
                throw new \Exception("Password cannot contain a new line character.");
            }
            $this->command = sprintf("echo %s:%s | /usr/bin/sudo /usr/sbin/chpasswd", escapeshellarg($userName), escapeshellarg($password));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setUserName($userName) : void
    {
        $this->userName = $userName;
    }
    public function getUserName() : ?string
    {
        return $this->userName;
    }
    public function setPassword($password) : void
    {
        $this->password = $password;
    }
    public function getPassword() : ?string
    {
        return $this->password;
    }
}