<?php

namespace App\Site\Application;

use App\System\Command\MoveFileCommand;
use App\System\Command\DeleteFileCommand;
use App\System\Command\DeleteDirectoryCommand;
use App\System\Command\DownloadFileCommand;
use App\System\Command\BsdTarExtractCommand;
use App\System\Command\ChownCommand;
use App\System\Command\FindChmodCommand;
use App\System\Command\WordPressConfigCreateCommand;
use App\System\Command\WordPressCoreInstallCommand;
use App\System\Command\WordPressSetConfigValueCommand;
class WordPressInstaller extends Installer
{
    private const LATEST_VERSION = "https://wordpress.org/latest.tar.gz";
    public function downloadAndExtractLatestVersion()
    {
        try {
            $siteUser = $this->siteEntity->getUser();
            $htdocsDirectory = $this->getHtdocsDirectory();
            $wordPressHtdocsDirectory = sprintf("%s/wordpress/", rtrim($htdocsDirectory, "/"));
            $rootDirectory = $this->getRootDirectory();
            $downloadTmpFile = sprintf("/home/%s/tmp/%s.tar.gz", $siteUser, uniqid());
            $deleteDownloadTmpFile = new DeleteFileCommand();
            $deleteDownloadTmpFile->setFile($downloadTmpFile);
            $downloadFileCommand = new DownloadFileCommand();
            $downloadFileCommand->setFile(self::LATEST_VERSION);
            $downloadFileCommand->setOutputFile($downloadTmpFile);
            $deleteRootDirectoryCommand = new DeleteDirectoryCommand();
            $deleteRootDirectoryCommand->setDirectory($rootDirectory);
            $tarExtractCommand = new BsdTarExtractCommand();
            $tarExtractCommand->setSourceFile($downloadTmpFile);
            $tarExtractCommand->setDestinationFile($htdocsDirectory);
            $moveFileCommand = new MoveFileCommand();
            $moveFileCommand->setSourceFile($wordPressHtdocsDirectory);
            $moveFileCommand->setDestinationFile($rootDirectory);
            $this->commandExecutor->execute($downloadFileCommand, 120);
            $this->commandExecutor->execute($deleteRootDirectoryCommand);
            $this->commandExecutor->execute($tarExtractCommand, 60);
            $this->commandExecutor->execute($moveFileCommand);
        } finally {
            $this->commandExecutor->execute($deleteDownloadTmpFile);
        }
    }
    public function createConfig(string $databaseHost, string $databaseName, string $databaseUserName, string $databaseUserPassword, string $locale = "en_US") : void
    {
        $locale = '' !== trim($locale) ? $locale : "en_US";
        if (1 !== preg_match('/^[a-zA-Z]{2,3}(_[a-zA-Z0-9]{2,4})?$/', $locale)) {
            $locale = "en_US";
        }
        $rootDirectory = $this->getRootDirectory();
        $createConfigCommand = new WordPressConfigCreateCommand();
        $createConfigCommand->setRootDirectory($rootDirectory);
        $createConfigCommand->setDatabaseHost($databaseHost);
        $createConfigCommand->setDatabaseName($databaseName);
        $createConfigCommand->setDatabaseUserName($databaseUserName);
        $createConfigCommand->setDatabaseUserPassword($databaseUserPassword);
        $createConfigCommand->setLocale($locale);
        $this->commandExecutor->execute($createConfigCommand);
    }
    public function setConfigValue(string $key, mixed $value, bool $raw) : void
    {
        $rootDirectory = $this->getRootDirectory();
        $setConfigValueCommand = new WordPressSetConfigValueCommand();
        $setConfigValueCommand->setRootDirectory($rootDirectory);
        $setConfigValueCommand->setKey($key);
        $setConfigValueCommand->setValue($value);
        $setConfigValueCommand->setRaw($raw);
        $this->commandExecutor->execute($setConfigValueCommand);
    }
    public function installCore(bool $isMultiSite, string $url, string $title, string $adminUserName, string $adminPassword, string $adminEmail) : void
    {
        $rootDirectory = $this->getRootDirectory();
        $installCoreCommand = new WordPressCoreInstallCommand();
        $installCoreCommand->setIsMultiSite($isMultiSite);
        $installCoreCommand->setRootDirectory($rootDirectory);
        $installCoreCommand->setUrl($url);
        $installCoreCommand->setTitle($title);
        $installCoreCommand->setAdminUser($adminUserName);
        $installCoreCommand->setAdminPassword($adminPassword);
        $installCoreCommand->setAdminEmail($adminEmail);
        $this->commandExecutor->execute($installCoreCommand, 90);
    }
    public function resetPermissions()
    {
        $siteUser = $this->siteEntity->getUser();
        $htdocsDirectory = $this->getHtdocsDirectory();
        $chownHtdocsCommand = new ChownCommand();
        $chownHtdocsCommand->setFile($htdocsDirectory);
        $chownHtdocsCommand->setRecursive(true);
        $chownHtdocsCommand->setUser($siteUser);
        $chownHtdocsCommand->setGroup($siteUser);
        $chmodHtdocsCommand = new FindChmodCommand();
        $chmodHtdocsCommand->setFile($htdocsDirectory);
        $chmodHtdocsCommand->setDirectoryChmod(770);
        $chmodHtdocsCommand->setFileChmod(660);
        $this->commandExecutor->execute($chownHtdocsCommand, 300);
        $this->commandExecutor->execute($chmodHtdocsCommand, 300);
    }
    private function getHtdocsDirectory() : string
    {
        $siteUser = $this->siteEntity->getUser();
        $htdocsDirectory = sprintf("/home/%s/htdocs/", $siteUser);
        return $htdocsDirectory;
    }
    private function getRootDirectory() : string
    {
        $htdocsDirectory = $this->getHtdocsDirectory();
        $rootDirectory = sprintf("%s/%s", rtrim($htdocsDirectory, "/"), $this->siteEntity->getRootDirectory());
        return $rootDirectory;
    }
}