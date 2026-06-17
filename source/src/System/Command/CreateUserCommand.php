<?php

namespace App\System\Command;

use App\System\Command;
class CreateUserCommand extends Command
{
    private ?string $userName = null;
    private ?string $password = null;
    private bool $createHomeDirectory = true;
    private ?string $homeDirectory = null;
    private ?string $skeletonDirectory = null;
    private ?string $shell = null;
    private ?string $group = null;
    private array $groups = [];
    private ?string $tmpFile = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $userName = $this->getUserName();
            $password = $this->getPassword();
            $shell = $this->getShell();
            $homeDirectory = $this->getHomeDirectory();
            $createHomeDirectory = $this->getCreateHomeDirectory();
            $skeletonDirectory = $this->getSkeletonDirectory();
            $group = $this->getGroup();
            $groups = $this->getGroups();
            $tmpFile = $this->getTmpFile();
            file_put_contents($tmpFile, $password);
            chmod($tmpFile, 0400);
            $this->command = sprintf("/usr/bin/sudo /usr/sbin/useradd -p %s -%s %s %s -s %s -d %s %s %s", sprintf("\$(/usr/bin/sudo /usr/bin/cat %s | /usr/bin/openssl passwd -6 -stdin)", $tmpFile), true === $createHomeDirectory ? "m" : "M", escapeshellarg($userName), false === empty($skeletonDirectory) ? sprintf("-k %s", escapeshellarg($skeletonDirectory)) : '', escapeshellarg($shell), escapeshellarg($homeDirectory), false === empty($group) ? sprintf("-g %s", escapeshellarg($group)) : '', $groups ? sprintf("-G %s", escapeshellarg(implode(",", $groups))) : '');
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function setUserName(string $userName) : void
    {
        $this->userName = $userName;
    }
    public function getUserName() : ?string
    {
        return $this->userName;
    }
    public function setPassword(string $password) : void
    {
        $this->password = $password;
    }
    public function getPassword() : ?string
    {
        return $this->password;
    }
    public function setHomeDirectory(string $homeDirectory) : void
    {
        $this->homeDirectory = $homeDirectory;
    }
    public function getHomeDirectory() : ?string
    {
        return $this->homeDirectory;
    }
    public function setSkeletonDirectory(string $skeletonDirectory) : void
    {
        $this->skeletonDirectory = $skeletonDirectory;
    }
    public function getSkeletonDirectory() : ?string
    {
        return $this->skeletonDirectory;
    }
    public function setShell(string $shell) : void
    {
        $this->shell = $shell;
    }
    public function getShell() : ?string
    {
        return $this->shell;
    }
    public function setGroups(array $groups) : void
    {
        $this->groups = $groups;
    }
    public function getGroups() : array
    {
        return $this->groups;
    }
    public function setGroup(string $group) : void
    {
        $this->group = $group;
    }
    public function getGroup() : ?string
    {
        return $this->group;
    }
    public function createHomeDirectory($flag) : void
    {
        $this->createHomeDirectory = (bool) $flag;
    }
    public function getCreateHomeDirectory() : bool
    {
        return $this->createHomeDirectory;
    }
    private function getTmpFile() : ?string
    {
        if (true === is_null($this->tmpFile)) {
            $this->tmpFile = sprintf("/tmp/.clp_tmp_%s", sha1(uniqid(mt_rand(), true)));
        }
        return $this->tmpFile;
    }
    public function __destruct()
    {
        $tmpFile = $this->getTmpFile();
        @unlink($tmpFile);
    }
}