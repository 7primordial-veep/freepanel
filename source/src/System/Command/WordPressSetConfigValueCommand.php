<?php

namespace App\System\Command;

use App\System\Command;
class WordPressSetConfigValueCommand extends Command
{
    private ?string $rootDirectory = null;
    private ?string $key = null;
    private ?string $value = null;
    private bool $raw = false;
    public function getCommand() : string
    {
        if (!$this->command) {
            $rootDirectory = $this->getRootDirectory();
            $key = $this->getKey();
            $value = $this->getValue();
            $isRaw = $this->isRaw();
            $this->command = sprintf("/usr/bin/sudo /bin/bash -c \"cd %s && /usr/bin/wp config set %s %s %s --allow-root\"", escapeshellarg($rootDirectory), escapeshellarg($key), escapeshellarg($value), true === $isRaw ? "--raw" : '');
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setRootDirectory(string $rootDirectory) : void
    {
        $this->rootDirectory = $rootDirectory;
    }
    public function getRootDirectory() : ?string
    {
        return $this->rootDirectory;
    }
    public function getKey() : ?string
    {
        return $this->key;
    }
    public function setKey(?string $key) : void
    {
        $this->key = $key;
    }
    public function getValue() : ?string
    {
        return $this->value;
    }
    public function setValue(?string $value) : void
    {
        $this->value = $value;
    }
    public function isRaw() : bool
    {
        return $this->raw;
    }
    public function setRaw(bool $raw) : void
    {
        $this->raw = $raw;
    }
}