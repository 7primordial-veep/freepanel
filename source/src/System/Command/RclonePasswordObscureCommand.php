<?php

namespace App\System\Command;

use App\System\Command;
class RclonePasswordObscureCommand extends Command
{
    private ?string $password = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $password = $this->getPassword();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/rclone obscure %s", escapeshellarg($password));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setPassword(?string $password) : void
    {
        $this->password = $password;
    }
    public function getPassword() : ?string
    {
        return $this->password;
    }
    public function getObscuredPassword() : ?string
    {
        $obscuredPassword = trim($this->getOutput());
        return $obscuredPassword;
    }
}