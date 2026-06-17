<?php

namespace App\Site;

use App\System\Command\CopyFileCommand;
use App\System\Command\DeleteFileCommand;
use App\System\Command\NginxConfigTestCommand;
use App\System\CommandExecutor;
use App\System\Command\ChownCommand;
use App\System\Command\ChmodCommand;
use App\System\Command\FindChmodCommand;
use App\System\Command\WriteFileCommand;
use App\System\Command\CreateDirectoryCommand;
use App\System\Command\DeleteDirectoryCommand;
use App\System\Command\ChangeUserHomeDirectoryCommand;
use App\System\Command\CreateUserCommand;
use App\System\Command\DeleteUserCommand;
use App\System\Command\ChangeUserPasswordCommand;
use App\System\Command\CreateSymlinkCommand;
use App\System\Command\ReadLinkCommand;
use App\System\Command\ServiceReloadCommand;
use App\Entity\BasicAuth as BasicAuthEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\FtpUser as FtpUserEntity;
use App\Entity\SshUser as SshUserEntity;
use App\Entity\Site as SiteEntity;
use App\Site\Ssl\LetsEncrypt\CertificateOrder;
use App\Site\Nginx\Exception\InvalidVhostException;
use App\Site\Nginx\Vhost\StaticTemplate;
use App\Site\Nginx\Vhost\NodejsTemplate;
use App\Site\Nginx\Vhost\PhpTemplate;
use App\Site\Nginx\Vhost\PythonTemplate;
use App\Site\Nginx\Vhost\ReverseProxyTemplate;
abstract class Updater
{
    public const NGINX_BASIC_AUTH_DIRECTORY = "/etc/nginx/basic-auth/";
    public const NGINX_VHOST_DIRECTORY = "/etc/nginx/sites-enabled/";
    public const NGINX_SSL_CERTIFICATES_DIRECTORY = "/etc/nginx/ssl-certificates/";
    public const CRON_DIRECTORY = "/etc/cron.d/";
    protected Site $site;
    protected CommandExecutor $commandExecutor;
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->commandExecutor = new CommandExecutor();
    }
    public function domainSettings() : void
    {
        $this->updateNginxVhost();
        $this->reloadNginxService();
    }
    public function updateNginxVhost() : void
    {
        $domainName = $this->site->getDomainName();
        $vhostFileContent = $this->site->getVhostTemplate();
        switch ($this->site->getType()) {
            case SiteEntity::TYPE_PHP:
                $vhostTemplate = new PhpTemplate($this->site);
                break;
            case SiteEntity::TYPE_NODEJS:
                $vhostTemplate = new NodejsTemplate($this->site);
                break;
            case SiteEntity::TYPE_STATIC:
                $vhostTemplate = new StaticTemplate($this->site);
                break;
            case SiteEntity::TYPE_PYTHON:
                $vhostTemplate = new PythonTemplate($this->site);
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $vhostTemplate = new ReverseProxyTemplate($this->site);
                break;
        }
        $vhostTemplate->setContent($vhostFileContent);
        $vhostFile = sprintf("%s/%s.conf", rtrim(self::NGINX_VHOST_DIRECTORY, "/"), $domainName);
        $vhostTemplate->build();
        $vhostTemplate->removeEmptyPlaceholders();
        $vhostContent = $vhostTemplate->getContent();
        $writeVhostFileCommand = new WriteFileCommand();
        $writeVhostFileCommand->setFile($vhostFile);
        $writeVhostFileCommand->setContent($vhostContent);
        $this->commandExecutor->execute($writeVhostFileCommand);
    }
    public function updateNginxVhostWithRollback() : void
    {
        try {
            $domainName = $this->site->getDomainName();
            $vhostFileContent = $this->site->getVhostTemplate();
            switch ($this->site->getType()) {
                case SiteEntity::TYPE_PHP:
                    $vhostTemplate = new PhpTemplate($this->site);
                    break;
                case SiteEntity::TYPE_NODEJS:
                    $vhostTemplate = new NodejsTemplate($this->site);
                    break;
                case SiteEntity::TYPE_STATIC:
                    $vhostTemplate = new StaticTemplate($this->site);
                    break;
                case SiteEntity::TYPE_PYTHON:
                    $vhostTemplate = new PythonTemplate($this->site);
                    break;
                case SiteEntity::TYPE_REVERSE_PROXY:
                    $vhostTemplate = new ReverseProxyTemplate($this->site);
                    break;
            }
            $vhostTemplate->setContent($vhostFileContent);
            $vhostFile = sprintf("%s/%s.conf", rtrim(self::NGINX_VHOST_DIRECTORY, "/"), $domainName);
            $vhostTemplate->build();
            $vhostTemplate->removeEmptyPlaceholders();
            $vhostContent = $vhostTemplate->getContent();
            $vhostBackupFile = sprintf("%s/%s.conf.bak", rtrim(self::NGINX_VHOST_DIRECTORY, "/"), $domainName);
            $copyVhostBackupFileCommand = new CopyFileCommand();
            $copyVhostBackupFileCommand->setSourceFile($vhostFile);
            $copyVhostBackupFileCommand->setDestinationFile($vhostBackupFile);
            $writeVhostFileCommand = new WriteFileCommand();
            $writeVhostFileCommand->setFile($vhostFile);
            $writeVhostFileCommand->setContent($vhostContent);
            $nginxConfigTestCommand = new NginxConfigTestCommand();
            $deleteVhostBackupFileCommand = new DeleteFileCommand();
            $deleteVhostBackupFileCommand->setFile($vhostBackupFile);
            $this->commandExecutor->execute($copyVhostBackupFileCommand);
            $this->commandExecutor->execute($writeVhostFileCommand);
            $this->commandExecutor->execute($nginxConfigTestCommand);
            if ("dev" != $_ENV["APP_ENV"]) {
                $reloadNginxServiceCommand = new ServiceReloadCommand();
                $reloadNginxServiceCommand->setServiceName("nginx");
                $this->commandExecutor->execute($reloadNginxServiceCommand);
            }
            $this->commandExecutor->execute($deleteVhostBackupFileCommand);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            if (true === isset($nginxConfigTestCommand)) {
                $errorMessage = $nginxConfigTestCommand->getOutput();
            }
            $restoreBackupFileCommand = new CopyFileCommand();
            $restoreBackupFileCommand->setSourceFile($vhostBackupFile);
            $restoreBackupFileCommand->setDestinationFile($vhostFile);
            $this->commandExecutor->execute($restoreBackupFileCommand);
            if (true === isset($reloadNginxServiceCommand)) {
                $this->commandExecutor->execute($reloadNginxServiceCommand);
            }
            $this->commandExecutor->execute($deleteVhostBackupFileCommand);
            throw new InvalidVhostException($errorMessage);
        }
    }
    public function createSshUser(SshUserEntity $sshUserEntity) : void
    {
        $site = $sshUserEntity->getSite();
        $siteUser = $site->getUser();
        $sshUserName = $sshUserEntity->getUserName();
        $sshUserPassword = $sshUserEntity->getPassword();
        $homeDirectory = sprintf("/home/%s", $sshUserName);
        $skeletonDirectory = realpath(dirname(__FILE__) . "/../../resources/etc/skel/ssh-user/");
        $createUserCommand = new CreateUserCommand();
        $createUserCommand->setUserName($sshUserName);
        $createUserCommand->setPassword($sshUserPassword);
        $createUserCommand->setGroup($siteUser);
        $createUserCommand->setShell("/bin/bash");
        $createUserCommand->setSkeletonDirectory($skeletonDirectory);
        $createUserCommand->setHomeDirectory($homeDirectory);
        $createUserCommand->createHomeDirectory(true);
        $this->commandExecutor->execute($createUserCommand);
        $symlinkDirectories = ["backups", "htdocs", "logs"];
        foreach ($symlinkDirectories as $symlinkDirectory) {
            $source = sprintf("/home/%s/%s", $siteUser, $symlinkDirectory);
            $destination = sprintf("/home/%s/%s", $sshUserName, $symlinkDirectory);
            $symlinkCommand = new CreateSymlinkCommand();
            $symlinkCommand->setRunAsUser($sshUserName);
            $symlinkCommand->setSource($source);
            $symlinkCommand->setDestination($destination);
            if (!("dev" != $_ENV["APP_ENV"])) {
                continue;
            }
            $this->commandExecutor->execute($symlinkCommand);
        }
        $sshDirectory = sprintf("/home/%s/.ssh", $sshUserName);
        $sshDirectoryChmodCommand = new FindChmodCommand();
        $sshDirectoryChmodCommand->setFile($sshDirectory);
        $sshDirectoryChmodCommand->setDirectoryChmod(700);
        $sshDirectoryChmodCommand->setFileChmod(600);
        $siteUserHomeDirectory = sprintf("/home/%s", $siteUser);
        $changeSiteUserHomeDirectoryPermissionsCommand = new ChmodCommand();
        $changeSiteUserHomeDirectoryPermissionsCommand->setFile($siteUserHomeDirectory);
        $changeSiteUserHomeDirectoryPermissionsCommand->setRecursive(false);
        $changeSiteUserHomeDirectoryPermissionsCommand->setChmod(750);
        $this->commandExecutor->execute($sshDirectoryChmodCommand);
        $this->commandExecutor->execute($changeSiteUserHomeDirectoryPermissionsCommand);
    }
    public function createFtpUser(FtpUserEntity $ftpUserEntity) : void
    {
        $site = $ftpUserEntity->getSite();
        $siteUser = $site->getUser();
        $ftpUserName = $ftpUserEntity->getUserName();
        $ftpUserPassword = $ftpUserEntity->getPassword();
        $homeDirectory = $ftpUserEntity->getHomeDirectory();
        $createUserCommand = new CreateUserCommand();
        $createUserCommand->setUserName($ftpUserName);
        $createUserCommand->setPassword($ftpUserPassword);
        $createUserCommand->setGroup($siteUser);
        $createUserCommand->setGroups(["ftp-user"]);
        $createUserCommand->setShell("/bin/false");
        $createUserCommand->setHomeDirectory($homeDirectory);
        $createUserCommand->createHomeDirectory(false);
        $siteUserHomeDirectory = sprintf("/home/%s", $siteUser);
        $changeSiteUserHomeDirectoryPermissionsCommand = new ChmodCommand();
        $changeSiteUserHomeDirectoryPermissionsCommand->setFile($siteUserHomeDirectory);
        $changeSiteUserHomeDirectoryPermissionsCommand->setRecursive(false);
        $changeSiteUserHomeDirectoryPermissionsCommand->setChmod(750);
        $this->commandExecutor->execute($createUserCommand);
        $this->commandExecutor->execute($changeSiteUserHomeDirectoryPermissionsCommand);
    }
    public function deleteUser(string $userName, $removeHomeDirectory = true) : void
    {
        $deleteUserCommand = new DeleteUserCommand();
        $deleteUserCommand->setUserName($userName);
        $deleteUserCommand->setRemoveHomeDirectory($removeHomeDirectory);
        $this->commandExecutor->execute($deleteUserCommand);
    }
    public function installCertificate(CertificateEntity $certificateEntity) : void
    {
        $domainName = $this->site->getDomainName();
        $privateKeyFile = sprintf("%s/%s.key", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $certificateFile = sprintf("%s/%s.crt", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $certificate = $certificateEntity->getCertificate();
        if (false === empty($certificateEntity->getCertificateChain())) {
            $certificate .= sprintf("%s%s", PHP_EOL, trim($certificateEntity->getCertificateChain()));
        }
        $writePrivateKeyFileCommand = new WriteFileCommand();
        $writePrivateKeyFileCommand->setFile($privateKeyFile);
        $writePrivateKeyFileCommand->setContent($certificateEntity->getPrivateKey());
        $writeCertificateFileCommand = new WriteFileCommand();
        $writeCertificateFileCommand->setFile($certificateFile);
        $writeCertificateFileCommand->setContent($certificate);
        $this->commandExecutor->execute($writePrivateKeyFileCommand);
        $this->commandExecutor->execute($writeCertificateFileCommand);
        $this->reloadService("nginx");
    }
    public function deleteLetsEncryptChallengeDirectory() : void
    {
        $rootDirectory = $this->getRootDirectory();
        $acemeChallengeDirectory = sprintf("%s/.well-known/acme-challenge/", rtrim($rootDirectory, "/"));
        $deleteAcmeChallengeDirectoryCommand = new DeleteDirectoryCommand();
        $deleteAcmeChallengeDirectoryCommand->setDirectory($acemeChallengeDirectory);
        $this->commandExecutor->execute($deleteAcmeChallengeDirectoryCommand);
    }
    public function createBasicAuthFile(BasicAuthEntity $basicAuthEntity) : void
    {
        $basicAuthFile = sprintf("%s/%s", rtrim(self::NGINX_BASIC_AUTH_DIRECTORY, "/"), $this->site->getDomainName());
        $basicAuthFileContent = sprintf("%s:%s", $basicAuthEntity->getUserName(), crypt($basicAuthEntity->getPassword(), sprintf("\$6\$%s\$", bin2hex(random_bytes(8)))));
        $writeBasicAuthFileCommand = new WriteFileCommand();
        $writeBasicAuthFileCommand->setFile($basicAuthFile);
        $writeBasicAuthFileCommand->setContent($basicAuthFileContent);
        $this->commandExecutor->execute($writeBasicAuthFileCommand);
    }
    public function createLetsEncryptChallengeFiles(CertificateOrder $certificateOrder) : void
    {
        $siteUser = $this->site->getUser();
        $rootDirectory = $this->getRootDirectory();
        $wellKnownDirectory = sprintf("%s/.well-known/", rtrim($rootDirectory, "/"));
        $acemeChallengeDirectory = sprintf("%s/.well-known/acme-challenge/", rtrim($rootDirectory, "/"));
        $challenges = $certificateOrder->getAuthorizationsChallenges();
        $createAcmeChallengeDirectoryCommand = new CreateDirectoryCommand();
        $createAcmeChallengeDirectoryCommand->setDirectory($acemeChallengeDirectory);
        $this->commandExecutor->execute($createAcmeChallengeDirectoryCommand);
        if (count($challenges)) {
            foreach ($challenges as $challenge) {
                $token = $challenge["token"] ?? null;
                $verificationContent = $challenge["verificationContent"] ?? null;
                if (!(false === is_null($token) && false === is_null($verificationContent))) {
                    continue;
                }
                $challengeFile = sprintf("%s/%s", rtrim($acemeChallengeDirectory, "/"), $token);
                $challengeWriteFileCommand = new WriteFileCommand();
                $challengeWriteFileCommand->setFile($challengeFile);
                $challengeWriteFileCommand->setContent($verificationContent);
                $this->commandExecutor->execute($challengeWriteFileCommand);
            }
        }
        $chownWellKnownDirectoryCommandCommand = new ChownCommand();
        $chownWellKnownDirectoryCommandCommand->setFile($wellKnownDirectory);
        $chownWellKnownDirectoryCommandCommand->setRecursive(true);
        $chownWellKnownDirectoryCommandCommand->setUser($siteUser);
        $chownWellKnownDirectoryCommandCommand->setGroup($siteUser);
        $chmodWellKnownDirectoryCommand = new FindChmodCommand();
        $chmodWellKnownDirectoryCommand->setFile($wellKnownDirectory);
        $chmodWellKnownDirectoryCommand->setDirectoryChmod(750);
        $chmodWellKnownDirectoryCommand->setFileChmod(770);
        $this->commandExecutor->execute($chownWellKnownDirectoryCommandCommand);
        $this->commandExecutor->execute($chmodWellKnownDirectoryCommand);
    }
    public function updateUserCrontab() : void
    {
        $siteUser = $this->site->getUser();
        $crontabFile = sprintf("%s/%s", rtrim(self::CRON_DIRECTORY, "/"), $siteUser);
        $crontabEntries = ["MAILTO=\"\""];
        $cronJobs = $this->site->getCronJobs();
        if (count($cronJobs)) {
            foreach ($cronJobs as $cronJob) {
                $crontabEntries[] = $cronJob->getCrontabExpression();
            }
        }
        $crontabContent = implode(PHP_EOL, $crontabEntries);
        $writeCrontabFileCommand = new WriteFileCommand();
        $writeCrontabFileCommand->setFile($crontabFile);
        $writeCrontabFileCommand->setContent($crontabContent);
        $this->commandExecutor->execute($writeCrontabFileCommand);
    }
    public function purgePageSpeedCache() : void
    {
        $siteUser = $this->site->getUser();
        $cacheDirectory = sprintf("/home/%s/tmp/pagespeed_cache", $siteUser);
        $purgePageSpeedCacheFilesCommand = new DeleteDirectoryCommand();
        $purgePageSpeedCacheFilesCommand->setDirectory($cacheDirectory);
        $this->commandExecutor->execute($purgePageSpeedCacheFilesCommand, 300);
    }
    public function updateUserSShKeys(string $userName, $sshKeys) : void
    {
        $authorizedKeysFile = sprintf("/home/%s/.ssh/authorized_keys", $userName);
        $readLinkCommand = new ReadLinkCommand();
        $readLinkCommand->setFile($authorizedKeysFile);
        $this->commandExecutor->execute($readLinkCommand);
        $readLinkPath = $readLinkCommand->getOutput();
        if (false === empty($readLinkPath) && $readLinkPath == $authorizedKeysFile) {
            $writeAuthorizedKeysFileCommand = new WriteFileCommand();
            $writeAuthorizedKeysFileCommand->setFile($authorizedKeysFile);
            $writeAuthorizedKeysFileCommand->setContent($sshKeys);
            $this->commandExecutor->execute($writeAuthorizedKeysFileCommand);
        }
    }
    public function changeUserHomeDirectory(string $userName, string $homeDirectory) : void
    {
        $changeUserHomeDirectoryCommand = new ChangeUserHomeDirectoryCommand();
        $changeUserHomeDirectoryCommand->setUserName($userName);
        $changeUserHomeDirectoryCommand->setHomeDirectory($homeDirectory);
        $this->commandExecutor->execute($changeUserHomeDirectoryCommand);
    }
    public function changeUserPassword(string $userName, string $password) : void
    {
        $changeUserPasswordCommand = new ChangeUserPasswordCommand();
        $changeUserPasswordCommand->setUserName($userName);
        $changeUserPasswordCommand->setPassword($password);
        $this->commandExecutor->execute($changeUserPasswordCommand);
    }
    protected function getRootDirectory() : string
    {
        $siteUser = $this->site->getUser();
        $rootDirectory = (string) $this->site->getRootDirectory();
        if (true === str_contains($rootDirectory, "..") || "/" === substr($rootDirectory, 0, 1)) {
            throw new \Exception("Invalid root directory");
        }
        $rootDirectory = sprintf("/home/%s/htdocs/%s", $siteUser, $rootDirectory);
        return $rootDirectory;
    }
    public function reloadNginxService() : void
    {
        $this->reloadService("nginx");
    }
    public function reloadService($serviceName)
    {
        if ("dev" != $_ENV["APP_ENV"]) {
            $reloadServiceCommand = new ServiceReloadCommand();
            $reloadServiceCommand->setServiceName($serviceName);
            $this->commandExecutor->execute($reloadServiceCommand);
        }
    }
}