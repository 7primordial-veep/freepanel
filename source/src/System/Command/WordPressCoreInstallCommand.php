<?php

namespace App\System\Command;

use App\System\Command;
class WordPressCoreInstallCommand extends Command
{
    private const TMP_FILE = "/tmp/.clp-wp-admin-password";
    private ?string $rootDirectory = null;
    private ?string $url = null;
    private ?string $title = null;
    private ?string $adminUser = null;
    private ?string $adminPassword = null;
    private ?string $adminEmail = null;
    private bool $isMultiSite = false;
    public function getCommand() : string
    {
        if (!$this->command) {
            $rootDirectory = $this->getRootDirectory();
            $url = $this->getUrl();
            $title = $this->getTitle();
            $adminUser = $this->getAdminUser();
            $adminPassword = $this->getAdminPassword();
            $adminEmail = $this->getAdminEmail();
            $isMultiSite = $this->isMultiSite();
            file_put_contents(self::TMP_FILE, $adminPassword);
            $this->command = sprintf("/usr/bin/sudo /bin/bash -c \"cd %s && /usr/bin/wp core %s --url=%s --title=%s --admin_user=%s --prompt=admin_password < %s  --admin_email=%s --allow-root\"", escapeshellarg($rootDirectory), true === $isMultiSite ? "multisite-install" : "install", escapeshellarg($url), escapeshellarg($title), escapeshellarg($adminUser), escapeshellarg(self::TMP_FILE), escapeshellarg($adminEmail));
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
    public function getUrl() : ?string
    {
        return $this->url;
    }
    public function setUrl(?string $url) : void
    {
        $this->url = $url;
    }
    public function getTitle() : ?string
    {
        return $this->title;
    }
    public function setTitle(?string $title) : void
    {
        $this->title = $title;
    }
    public function getAdminUser() : ?string
    {
        return $this->adminUser;
    }
    public function setAdminUser(?string $adminUser) : void
    {
        $this->adminUser = $adminUser;
    }
    public function getAdminPassword() : ?string
    {
        return $this->adminPassword;
    }
    public function setAdminPassword(?string $adminPassword) : void
    {
        $this->adminPassword = $adminPassword;
    }
    public function getAdminEmail() : ?string
    {
        return $this->adminEmail;
    }
    public function setAdminEmail(?string $adminEmail) : void
    {
        $this->adminEmail = $adminEmail;
    }
    public function isMultiSite() : bool
    {
        return $this->isMultiSite;
    }
    public function setIsMultiSite(bool $flag) : void
    {
        $this->isMultiSite = $flag;
    }
    public function __destruct()
    {
        @unlink(self::TMP_FILE);
    }
}