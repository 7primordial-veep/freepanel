<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\SiteCommand as SiteCommand;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\LetsEncryptClient;
use App\Site\Ssl\LetsEncrypt\DomainValidationException;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\Notification;
use App\Notification\NotificationQueue;
class LetsEncryptRenewCertificatesCommand extends SiteCommand
{
    const RENEW_DAYS_BEFORE_EXPIRATION = 7;
    protected function configure() : void
    {
        $this->setName("lets-encrypt:renew:certificates");
        $this->setDescription("clpctl lets-encrypt:renew:certificates");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $siteEntities = $this->siteEntityManager->findAll();
            if (count($siteEntities)) {
                foreach ($siteEntities as $siteEntity) {
                    $certificateEntity = $siteEntity->getCertificate();
                    if (null === $certificateEntity || CertificateEntity::TYPE_LETS_ENCRYPT !== $certificateEntity->getType()) {
                    continue;
                    }
                    $this->renewCertificate($output, $certificateEntity);
                }
            }
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
    private function renewCertificate(OutputInterface $output, CertificateEntity $certificateEntity)
    {
        $now = new \DateTime("now");
        $expiresAt = $certificateEntity->getExpiresAt();
        $daysToExpire = round(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400, 0);
        if ($daysToExpire <= self::RENEW_DAYS_BEFORE_EXPIRATION) {
            try {
                $siteEntity = $certificateEntity->getSite();
                $domainName = $siteEntity->getDomainName();
                $site = $this->getSite($domainName);
                $domains = $certificateEntity->getDomains();
                $letsEncryptPrivateKey = $this->getConfigValue("le_private_key");
                $privateKey = new PrivateKey($letsEncryptPrivateKey);
                $letsEncryptClient = new LetsEncryptClient($privateKey);
                $letsEncryptClient->registerAccount();
                $certificateOrder = $letsEncryptClient->requestOrder($domains);
                $siteUpdater = $this->getSiteUpdater($site);
                $siteUpdater->deleteLetsEncryptChallengeDirectory();
                $siteUpdater->createLetsEncryptChallengeFiles($certificateOrder);
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
                $certificateEntity->setCsr($certificate->getCsr());
                $certificateEntity->setPrivateKey($certificate->getPrivateKey());
                $certificateEntity->setCertificate($certificate->getCertificate());
                $certificateEntity->setCertificateChain($certificate->getCertificateChain());
                $siteUpdater->installCertificate($certificateEntity);
                $siteEntity->setCertificate($certificateEntity);
                $this->siteEntityManager->updateEntity($siteEntity);
                $renewingSuccessMessage = sprintf("Certificate renewing for the domain \"%s\" was successful.", $domainName);
                $output->writeln(sprintf("<info>%s</info>", $renewingSuccessMessage));
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
                if (true === isset($siteUpdater)) {
                    $siteUpdater->deleteLetsEncryptChallengeDirectory();
                }
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