<?php

namespace App\Site;

use App\Entity\Site as SiteEntity;
use App\System\CommandExecutor;
use App\System\Command\CreateUserCommand;
use App\System\Command\CreateDirectoryCommand;
use App\System\Command\ServiceReloadCommand;
use App\System\Command\ChownCommand;
use App\System\Command\FindChmodCommand;
use App\System\Command\WriteFileCommand;
use App\Site\Nginx\Vhost\PhpTemplate;
use App\Site\Nginx\Vhost\StaticTemplate;
use App\Site\Nginx\Vhost\NodejsTemplate;
use App\Site\Nginx\Vhost\PythonTemplate;
use App\Site\Nginx\Vhost\ReverseProxyTemplate;
abstract class Creator
{
    public const NGINX_VHOST_DIRECTORY = "/etc/nginx/sites-enabled/";
    public const NGINX_SSL_CERTIFICATES_DIRECTORY = "/etc/nginx/ssl-certificates/";
    public const LOGROTATE_DIRECTORY = "/etc/logrotate.d/";
    protected Site $site;
    protected CommandExecutor $commandExecutor;
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->commandExecutor = new CommandExecutor();
    }
    public function createUser() : void
    {
        $siteUser = $this->site->getUser();
        $siteUserPassword = $this->site->getUserPassword();
        $homeDirectory = sprintf("/home/%s", $siteUser);
        $skeletonDirectory = realpath(dirname(__FILE__) . "/../../resources/etc/skel/site-user/");
        $createUserCommand = new CreateUserCommand();
        $createUserCommand->setUserName($siteUser);
        $createUserCommand->setPassword($siteUserPassword);
        $createUserCommand->setShell("/bin/bash");
        $createUserCommand->setSkeletonDirectory($skeletonDirectory);
        $createUserCommand->setHomeDirectory($homeDirectory);
        $createUserCommand->createHomeDirectory(true);
        $this->commandExecutor->execute($createUserCommand);
    }
    public function createRootDirectory()
    {
        $rootDirectory = $this->getRootDirectory();
        $createRootDirectoryCommand = new CreateDirectoryCommand();
        $createRootDirectoryCommand->setDirectory($rootDirectory);
        $this->commandExecutor->execute($createRootDirectoryCommand);
    }
    public function createNginxVhost() : void
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
    public function createLogrotateFile()
    {
        $siteUser = $this->site->getUser();
        $logrotateTemplateFile = realpath(dirname(__FILE__) . "/../../resources/etc/logrotate/template");
        $logrotateTemplate = file_get_contents($logrotateTemplateFile);
        $logrotateFile = sprintf("%s/%s", rtrim(self::LOGROTATE_DIRECTORY, "/"), $siteUser);
        $logrotateFileContent = str_replace(["{{user}}", "{{group}}"], [$siteUser, $siteUser], $logrotateTemplate);
        $writeLogrotateFileCommand = new WriteFileCommand();
        $writeLogrotateFileCommand->setFile($logrotateFile);
        $writeLogrotateFileCommand->setContent($logrotateFileContent);
        $this->commandExecutor->execute($writeLogrotateFileCommand);
    }
    public function resetPermissions() : void
    {
        $siteUser = $this->site->getUser();
        $homeDirectory = sprintf("/home/%s", $siteUser);
        $chownCommand = new ChownCommand();
        $chownCommand->setFile($homeDirectory);
        $chownCommand->setRecursive(true);
        $chownCommand->setUser($siteUser);
        $chownCommand->setGroup($siteUser);
        $homeDirectoryChmodCommand = new FindChmodCommand();
        $homeDirectoryChmodCommand->setFile($homeDirectory);
        $homeDirectoryChmodCommand->setDirectoryChmod(770);
        $homeDirectoryChmodCommand->setFileChmod(770);
        $userSshDirectory = sprintf("/home/%s/.ssh", $siteUser);
        $userSshDirectoryChmodCommand = new FindChmodCommand();
        $userSshDirectoryChmodCommand->setFile($userSshDirectory);
        $userSshDirectoryChmodCommand->setDirectoryChmod(700);
        $userSshDirectoryChmodCommand->setFileChmod(600);
        $this->commandExecutor->execute($chownCommand, 90);
        $this->commandExecutor->execute($homeDirectoryChmodCommand, 90);
        $this->commandExecutor->execute($userSshDirectoryChmodCommand, 90);
    }
    public function reloadNginxService() : void
    {
        $this->reloadService("nginx");
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
    public function createPrivateKeyAndCertificate() : void
    {
        $domainName = $this->site->getDomainName();
        $certificate = $this->site->getCertificate();
        $privateKeyFile = sprintf("%s/%s.key", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $certificateFile = sprintf("%s/%s.crt", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $writePrivateKeyFileCommand = new WriteFileCommand();
        $writePrivateKeyFileCommand->setFile($privateKeyFile);
        $writePrivateKeyFileCommand->setContent($certificate->getPrivateKey());
        $writeCertificateFileCommand = new WriteFileCommand();
        $writeCertificateFileCommand->setFile($certificateFile);
        $writeCertificateFileCommand->setContent($certificate->getCertificate());
        $this->commandExecutor->execute($writePrivateKeyFileCommand);
        $this->commandExecutor->execute($writeCertificateFileCommand);
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