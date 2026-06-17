<?php

namespace App\Site;

use App\System\CommandExecutor;
use App\System\Command\DeleteFileCommand;
use App\System\Command\DeleteUserCommand;
use App\System\Command\KillUserProcessesCommand;
use App\System\Command\ServiceReloadCommand;
use App\Database\Manager as DatabaseManager;
abstract class Deleter
{
    public const NGINX_BASIC_AUTH_DIRECTORY = "/etc/nginx/basic-auth/";
    public const NGINX_VHOST_DIRECTORY = "/etc/nginx/sites-enabled/";
    public const NGINX_SSL_CERTIFICATES_DIRECTORY = "/etc/nginx/ssl-certificates/";
    public const LOGROTATE_DIRECTORY = "/etc/logrotate.d/";
    public const CRON_DIRECTORY = "/etc/cron.d/";
    protected Site $site;
    protected CommandExecutor $commandExecutor;
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->commandExecutor = new CommandExecutor();
    }
    public function delete() : void
    {
        $this->deleteVhost();
        $this->deleteCertificates();
        $this->deleteBasicAuthFile();
        $this->deleteFtpUsers();
        $this->deleteSshUsers();
        $this->killSiteUserProcesses();
        $this->deleteSiteUser();
        $this->deleteCrontab();
        $this->deleteLogrotateFile();
        $this->deleteDatabases();
    }
    protected function deleteVhost() : void
    {
        $domainName = $this->site->getDomainName();
        $vhostFile = sprintf("%s/%s.conf", rtrim(self::NGINX_VHOST_DIRECTORY, "/"), $domainName);
        $vhostFileDeleteCommand = new DeleteFileCommand();
        $vhostFileDeleteCommand->setFile($vhostFile);
        $this->commandExecutor->execute($vhostFileDeleteCommand);
        $this->reloadService("nginx");
    }
    protected function deleteCertificates() : void
    {
        $domainName = $this->site->getDomainName();
        $certificateFile = sprintf("%s/%s.crt", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $keyFile = sprintf("%s/%s.key", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $deleteCertificateFileCommand = new DeleteFileCommand();
        $deleteCertificateFileCommand->setFile($certificateFile);
        $deleteKeyFileCommand = new DeleteFileCommand();
        $deleteKeyFileCommand->setFile($keyFile);
        $this->commandExecutor->execute($deleteCertificateFileCommand);
        $this->commandExecutor->execute($deleteKeyFileCommand);
    }
    protected function deleteBasicAuthFile() : void
    {
        $basicAuthFile = sprintf("%s/%s", rtrim(self::NGINX_BASIC_AUTH_DIRECTORY, "/"), $this->site->getDomainName());
        $deleteBasicAuthFileCommand = new DeleteFileCommand();
        $deleteBasicAuthFileCommand->setFile($basicAuthFile);
        $this->commandExecutor->execute($deleteBasicAuthFileCommand);
    }
    protected function killSiteUserProcesses() : void
    {
        $siteUser = $this->site->getUser();
        $killUserProcessesCommand = new KillUserProcessesCommand();
        $killUserProcessesCommand->setUserName($siteUser);
        $killUserProcessesCommand->setRunInBackground(true);
        $this->commandExecutor->execute($killUserProcessesCommand);
    }
    protected function deleteSiteUser() : void
    {
        $siteUser = $this->site->getUser();
        $this->deleteUser($siteUser, true);
    }
    protected function deleteCrontab() : void
    {
        $siteUser = $this->site->getUser();
        $crontabFile = sprintf("%s/%s", rtrim(self::CRON_DIRECTORY, "/"), $siteUser);
        $deleteCrontabFileCommand = new DeleteFileCommand();
        $deleteCrontabFileCommand->setFile($crontabFile);
        $this->commandExecutor->execute($deleteCrontabFileCommand);
    }
    public function deleteLogrotateFile() : void
    {
        $siteUser = $this->site->getUser();
        $logrotateFile = sprintf("%s/%s", rtrim(self::LOGROTATE_DIRECTORY, "/"), $siteUser);
        $deleteLogrotateFileCommand = new DeleteFileCommand();
        $deleteLogrotateFileCommand->setFile($logrotateFile);
        $this->commandExecutor->execute($deleteLogrotateFileCommand);
    }
    protected function deleteUser(string $userName, $removeHomeDirectory = true) : void
    {
        $deleteUserCommand = new DeleteUserCommand();
        $deleteUserCommand->setUserName($userName);
        $deleteUserCommand->setRemoveHomeDirectory($removeHomeDirectory);
        $this->commandExecutor->execute($deleteUserCommand);
    }
    protected function deleteFtpUsers() : void
    {
        $ftpUsers = $this->site->getFtpUsers();
        if (count($ftpUsers)) {
            foreach ($ftpUsers as $ftpUser) {
                $userName = $ftpUser->getUserName();
                $this->deleteUser($userName, false);
            }
        }
    }
    protected function deleteSshUsers() : void
    {
        $sshUsers = $this->site->getSshUsers();
        if (count($sshUsers)) {
            foreach ($sshUsers as $sshUser) {
                $userName = $sshUser->getUserName();
                $this->deleteUser($userName, true);
            }
        }
    }
    protected function deleteDatabases() : void
    {
        $databases = $this->site->getDatabases();
        if (count($databases)) {
            foreach ($databases as $database) {
                $databaseServer = $database->getDatabaseServer();
                $databaseManager = new DatabaseManager($databaseServer);
                $databaseManager->deleteDatabase($database, true);
            }
        }
    }
    public function reloadNginxService() : void
    {
        $this->reloadService("nginx");
    }
    public function reloadService($serviceName) : void
    {
        if ("dev" != $_ENV["APP_ENV"]) {
            $reloadServiceCommand = new ServiceReloadCommand();
            $reloadServiceCommand->setServiceName($serviceName);
            $this->commandExecutor->execute($reloadServiceCommand);
        }
    }
}