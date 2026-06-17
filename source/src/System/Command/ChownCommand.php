<?php

namespace App\System\Command;

use App\System\Command;
class ChownCommand extends Command
{
    private ?string $user = null;
    private ?string $group = null;
    private ?string $file = null;
    private bool $recursive = false;
    public function getCommand() : string
    {
        if (!$this->command) {
            $user = $this->getUser();
            $group = $this->getGroup();
            $file = $this->getFile();
            $recursive = $this->getRecursive();
            $this->command = sprintf("/usr/bin/sudo /bin/chown %s %s:%s %s", true === $recursive ? "-R" : '', $user, $group, escapeshellarg($file));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setUser(string $user) : void
    {
        $this->user = $user;
    }
    public function getUser() : ?string
    {
        return $this->user;
    }
    public function setGroup(string $group) : void
    {
        $this->group = $group;
    }
    public function getGroup() : ?string
    {
        return $this->group;
    }
    public function setFile(string $file) : void
    {
        $this->file = $file;
    }
    public function getFile() : ?string
    {
        return $this->file;
    }
    public function setRecursive($flag) : void
    {
        $this->recursive = (bool) $flag;
    }
    public function getRecursive() : bool
    {
        return $this->recursive;
    }
}