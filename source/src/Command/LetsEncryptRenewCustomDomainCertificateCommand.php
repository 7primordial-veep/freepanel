<?php

namespace App\Command;

use App\Security\Admin\CustomDomain;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\SiteCommand as SiteCommand;
use App\System\CommandExecutor;
use App\Site\Ssl\CertificateParser;
use App\Site\Ssl\Certificate as SslCertificate;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\LetsEncryptClient;
use App\Site\Ssl\LetsEncrypt\DomainValidationException;
use App\Security\Admin\CustomDomain as AdminCustomDomain;
use App\System\Command\CheckIfFileExistsCommand;
use App\System\Command\CatFileCommand;
use App\Notification\NotificationQueue;
use App\Entity\Notification;
class LetsEncryptRenewCustomDomainCertificateCommand extends SiteCommand
{
    const RENEW_DAYS_BEFORE_EXPIRATION = 7;
    protected function configure() : void
    {
        $this->setName("lets-encrypt:renew:custom-domain:certificate");
        $this->setDescription("clpctl lets-encrypt:renew:custom-domain:certificate");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $configManager = $this->getConfigManager();
            $domainName = $configManager->get("custom_domain");
            if (false === empty($domainName)) {
                $this->renewCertificate($output, $domainName);
            }
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
    private function renewCertificate(OutputInterface $output, $domainName)
    {
        try {
            $commandExecutor = new CommandExecutor();
            $certificateContentCommand = new CatFileCommand();
            $certificateContentCommand->setFile(AdminCustomDomain::CERTIFICATE_FILE);
            $commandExecutor->execute($certificateContentCommand);
            $certificateContent = $certificateContentCommand->getOutput();
            $adminCustomDomain = new AdminCustomDomain();
            if (false === empty($certificateContent)) {
                $certificateParser = new CertificateParser();
                $certificate = new SslCertificate();
                $certificate->setCertificate($certificateContent);
                $parsedCertificate = $certificateParser->parse($certificate);
                if (false === $parsedCertificate->isSelfSigned()) {
                    $now = new \DateTime("now");
                    $expiresAt = $parsedCertificate->getValidTo();
                    $daysToExpire = round(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400, 0);
                    if ($daysToExpire <= self::RENEW_DAYS_BEFORE_EXPIRATION) {
                        $domains = [$domainName];
                        $configManager = $this->getConfigManager();
                        $vhostTemplateFile = realpath(dirname(__FILE__) . "/../../resources/nginx/vhost_template/custom-domain");
                        $vhostTemplate = file_get_contents($vhostTemplateFile);
                        $letsEncryptPrivateKey = $configManager->get("le_private_key");
                        $privateKey = new PrivateKey($letsEncryptPrivateKey);
                        $letsEncryptClient = new LetsEncryptClient($privateKey);
                        $letsEncryptClient->registerAccount();
                        $certificateOrder = $letsEncryptClient->requestOrder($domains);
                        $adminCustomDomain->deleteLetsEncryptChallengeFiles();
                        $adminCustomDomain->createLetsEncryptChallengeFiles($certificateOrder);
                        $validationErrors = $letsEncryptClient->validateDomains($certificateOrder);
                        if (!(true === empty($validationErrors))) {
                            $domainValidationException = new DomainValidationException("Domain validation failed");
                            $domainValidationException->setValidationErrors($validationErrors);
                            throw $domainValidationException;
                        }
                        $distinguishedNameDomains = $domains;
                        $commonName = array_shift($distinguishedNameDomains);
                        $distinguishedName = new DistinguishedName($commonName, $distinguishedNameDomains);
                        $rsaKeyGenerator = new RsaKeyGenerator();
                        $privateKey = $rsaKeyGenerator->generatePrivateKey();
                        $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
                        $csr = $csrGenerator->generate();
                        $certificate = $letsEncryptClient->finalizeOrder($certificateOrder, $privateKey, $csr);
                        $adminCustomDomain->writePrivateKeyAndCertificate($certificate);
                        $adminCustomDomain->writeVhostFile($domainName, $vhostTemplate);
                        $adminCustomDomain->reloadNginx();
                        $renewingSuccessMessage = sprintf("Certificate renewing for the domain \"%s\" was successful.", $domainName);
                        $output->writeln(sprintf("<info>%s</info>", $renewingSuccessMessage));
                    }
                }
            }
        } catch (\Exception|DomainValidationException $e) {
            if ($e instanceof DomainValidationException) {
                $validationErrors = [];
                foreach ($e->getValidationErrors() as $domainName => $validationError) {
                    $validationErrors[] = sprintf("%s: %s", $domainName, $validationError);
                }
                $errorMessage = implode(", ", $validationErrors);
            } else {
                $errorMessage = $e->getMessage();
            }
            $subject = sprintf("Let's Encrypt Certificate renew failed: %s.", $domainName);
            $this->addNotification($subject, $errorMessage);
        } finally {
            if (true === isset($adminCustomDomain)) {
                $adminCustomDomain->deleteLetsEncryptChallengeFiles();
            }
        }
    }
    private function addNotification(string $subject, string $errorMessage) : void
    {
        $notification = new Notification();
        $notification->setSubject($subject);
        $notification->setMessage($errorMessage);
        $notification->setSeverity(Notification::SEVERITY_CRITICAL);
        NotificationQueue::addNotification($notification);
    }
}