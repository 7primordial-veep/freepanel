<?php

namespace App\Security\Admin;

use App\System\Command\ServiceReloadCommand;
use App\System\CommandExecutor;
use App\System\Command\WriteFileCommand;
use App\System\Command\DeleteFileCommand;
use App\System\Command\ChownCommand;
use App\System\Command\ChmodCommand;
use App\System\Command\FindChmodCommand;
use App\Site\Ssl\LetsEncrypt\CertificateOrder;
use App\Site\Ssl\Certificate;
class CustomDomain
{
    public const WELL_KNOWN_DIRECTORY = "/home/clp/htdocs/app/files/public/.well-known/";
    public const ACME_CHALLENGE_DIRECTORY = "/home/clp/htdocs/app/files/public/.well-known/acme-challenge/";
    public const PRIVATE_KEY_FILE = "/etc/nginx/ssl-certificates/custom-domain.key";
    public const CERTIFICATE_FILE = "/etc/nginx/ssl-certificates/custom-domain.crt";
    public const VHOST_FILE = "/etc/nginx/sites-enabled/custom-domain.conf";
    public const CUSTOM_DOMAIN_MOTD_FILE = "/etc/.clp_custom_domain";
    private CommandExecutor $commandExecutor;
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function deleteLetsEncryptChallengeFiles()
    {
        $deleteLetsEncryptChallengeFiles = sprintf("%s/*", rtrim(self::ACME_CHALLENGE_DIRECTORY, "/"));
        $deleteLetsEncryptChallengeFilesCommand = new DeleteFileCommand();
        $deleteLetsEncryptChallengeFilesCommand->setFile($deleteLetsEncryptChallengeFiles);
        $this->commandExecutor->execute($deleteLetsEncryptChallengeFilesCommand);
    }
    public function createLetsEncryptChallengeFiles(CertificateOrder $certificateOrder) : void
    {
        $challenges = $certificateOrder->getAuthorizationsChallenges();
        if (count($challenges)) {
            foreach ($challenges as $challenge) {
                $token = $challenge["token"] ?? null;
                $verificationContent = $challenge["verificationContent"] ?? null;
                if (!(false === is_null($token) && false === is_null($verificationContent))) {
                    continue;
                }
                $challengeFile = sprintf("%s/%s", rtrim(self::ACME_CHALLENGE_DIRECTORY, "/"), $token);
                $challengeWriteFileCommand = new WriteFileCommand();
                $challengeWriteFileCommand->setFile($challengeFile);
                $challengeWriteFileCommand->setContent($verificationContent);
                $this->commandExecutor->execute($challengeWriteFileCommand);
            }
        }
        $chownWellKnownDirectoryCommandCommand = new ChownCommand();
        $chownWellKnownDirectoryCommandCommand->setFile(self::WELL_KNOWN_DIRECTORY);
        $chownWellKnownDirectoryCommandCommand->setRecursive(true);
        $chownWellKnownDirectoryCommandCommand->setUser("clp");
        $chownWellKnownDirectoryCommandCommand->setGroup("clp");
        $chmodWellKnownDirectoryCommand = new FindChmodCommand();
        $chmodWellKnownDirectoryCommand->setFile(self::WELL_KNOWN_DIRECTORY);
        $chmodWellKnownDirectoryCommand->setDirectoryChmod(750);
        $chmodWellKnownDirectoryCommand->setFileChmod(770);
        $this->commandExecutor->execute($chownWellKnownDirectoryCommandCommand);
        $this->commandExecutor->execute($chmodWellKnownDirectoryCommand);
    }
    public function writePrivateKeyAndCertificate(Certificate $certificate) : void
    {
        $certificateContent = $certificate->getCertificate();
        if (false === empty($certificate->getCertificateChain())) {
            $certificateContent .= sprintf("%s%s", PHP_EOL, trim($certificate->getCertificateChain()));
        }
        $writePrivateKeyFileCommand = new WriteFileCommand();
        $writePrivateKeyFileCommand->setFile(self::PRIVATE_KEY_FILE);
        $writePrivateKeyFileCommand->setContent($certificate->getPrivateKey());
        $writeCertificateFileCommand = new WriteFileCommand();
        $writeCertificateFileCommand->setFile(self::CERTIFICATE_FILE);
        $writeCertificateFileCommand->setContent($certificateContent);
        $this->commandExecutor->execute($writePrivateKeyFileCommand);
        $this->commandExecutor->execute($writeCertificateFileCommand);
    }
    public function writeVhostFile(string $domainName, string $vhostTemplate) : void
    {
        $vhostTemplate = str_replace("{{server_name}}", $domainName, $vhostTemplate);
        $writeVhostFileCommand = new WriteFileCommand();
        $writeVhostFileCommand->setFile(self::VHOST_FILE);
        $writeVhostFileCommand->setContent($vhostTemplate);
        $this->commandExecutor->execute($writeVhostFileCommand);
    }
    public function writeMotdFile(string $domainName) : void
    {
        $writeMotdFileCommand = new WriteFileCommand();
        $writeMotdFileCommand->setFile(self::CUSTOM_DOMAIN_MOTD_FILE);
        $writeMotdFileCommand->setContent($domainName);
        $chownMotdFileCommand = new ChownCommand();
        $chownMotdFileCommand->setFile(self::CUSTOM_DOMAIN_MOTD_FILE);
        $chownMotdFileCommand->setUser("clp");
        $chownMotdFileCommand->setGroup("clp");
        $chmodMotdFileCommand = new ChmodCommand();
        $chmodMotdFileCommand->setFile(self::CUSTOM_DOMAIN_MOTD_FILE);
        $chmodMotdFileCommand->setChmod(744);
        $this->commandExecutor->execute($writeMotdFileCommand);
        $this->commandExecutor->execute($chownMotdFileCommand);
        $this->commandExecutor->execute($chmodMotdFileCommand);
    }
    public function reloadNginx() : void
    {
        $this->reloadService("nginx");
    }
    public function delete() : void
    {
        $deletePrivateKeyFileCommand = new DeleteFileCommand();
        $deletePrivateKeyFileCommand->setFile(self::PRIVATE_KEY_FILE);
        $deleteCertificateFileCommand = new DeleteFileCommand();
        $deleteCertificateFileCommand->setFile(self::CERTIFICATE_FILE);
        $deleteVhostFileCommand = new DeleteFileCommand();
        $deleteVhostFileCommand->setFile(self::VHOST_FILE);
        $deleteMotdFileCommand = new DeleteFileCommand();
        $deleteMotdFileCommand->setFile(self::CUSTOM_DOMAIN_MOTD_FILE);
        $this->commandExecutor->execute($deletePrivateKeyFileCommand);
        $this->commandExecutor->execute($deleteCertificateFileCommand);
        $this->commandExecutor->execute($deleteVhostFileCommand);
        $this->commandExecutor->execute($deleteMotdFileCommand);
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