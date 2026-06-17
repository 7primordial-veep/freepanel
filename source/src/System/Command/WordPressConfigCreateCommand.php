<?php

namespace App\System\Command;

use App\System\Command;
class WordPressConfigCreateCommand extends Command
{
    private ?string $rootDirectory = null;
    private ?string $databaseHost = null;
    private ?string $databaseName = null;
    private ?string $databaseUserName = null;
    private ?string $databaseUserPassword = null;
    private ?string $locale = null;
    private ?string $tmpFile = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $rootDirectory = $this->getRootDirectory();
            $databaseHost = $this->getDatabaseHost();
            $databaseName = $this->getDatabaseName();
            $databaseUserName = $this->getDatabaseUserName();
            $databaseUserPassword = $this->getDatabaseUserPassword();
            $locale = $this->getLocale();
            $tmpFile = $this->getTmpFile();
            file_put_contents($tmpFile, $databaseUserPassword);
            chmod($tmpFile, 0400);
            $this->command = sprintf("/usr/bin/sudo /bin/bash -c \"cd %s && /usr/bin/wp config create --dbhost=%s --dbname=%s --dbuser=%s --locale=%s --allow-root --prompt=dbpass < %s\"", escapeshellarg($rootDirectory), escapeshellarg($databaseHost), escapeshellarg($databaseName), escapeshellarg($databaseUserName), escapeshellarg($locale), $tmpFile);
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
    public function getDatabaseHost() : ?string
    {
        return $this->databaseHost;
    }
    public function setDatabaseHost(?string $databaseHost) : void
    {
        $this->databaseHost = $databaseHost;
    }
    public function getDatabaseName() : ?string
    {
        return $this->databaseName;
    }
    public function setDatabaseName(?string $databaseName) : void
    {
        $this->databaseName = $databaseName;
    }
    public function getDatabaseUserName() : ?string
    {
        return $this->databaseUserName;
    }
    public function setDatabaseUserName(?string $databaseUserName) : void
    {
        $this->databaseUserName = $databaseUserName;
    }
    public function getDatabaseUserPassword() : ?string
    {
        return $this->databaseUserPassword;
    }
    public function setDatabaseUserPassword(?string $databaseUserPassword) : void
    {
        $this->databaseUserPassword = $databaseUserPassword;
    }
    public function getLocale() : ?string
    {
        return $this->locale;
    }
    public function setLocale(?string $locale) : void
    {
        $this->locale = $locale;
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