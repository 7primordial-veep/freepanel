<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\SiteCommand as SiteCommand;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\LetsEncryptClient;
use App\Site\Ssl\LetsEncrypt\DomainValidationException;
use App\Entity\Certificate as CertificateEntity;
class LetsEncryptInstallCertificateCommand extends SiteCommand
{
    protected function configure() : void
    {
        $this->setName("lets-encrypt:install:certificate");
        $this->setDescription("clpctl lets-encrypt:install:certificate --domainName=www.domain.com --subjectAlternativeName=domain1.com,www.domain1.com");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("subjectAlternativeName", null, InputOption::VALUE_OPTIONAL, '', '');
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $domainName = trim($input->getOption("domainName"));
            $subjectAlternativeName = array_map("trim", array_filter(explode(",", trim($input->getOption("subjectAlternativeName")))));
            $site = $this->getSite($domainName);
            $siteEntity = $this->getSiteEntity($domainName);
            if (!(false === is_null($siteEntity))) {
                throw new \Exception(sprintf("DomainName \"%s\" does not exist.", $domainName));
            }
            $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
            $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
            $subdomain = $resolvedDomainName->subDomain()->toString();
            $subdomain = false === empty($subdomain) ? $subdomain : null;
            if (true === is_null($subdomain) || "www" == $subdomain) {
                $domains = [$registrableDomain, sprintf("www.%s", $registrableDomain)];
            } else {
                $domains = [$domainName];
            }
            $domains = array_unique(array_merge($domains, $subjectAlternativeName));
            $letsEncryptPrivateKey = $this->getConfigValue("le_private_key");
            $privateKey = new PrivateKey($letsEncryptPrivateKey);
            $letsEncryptClient = new LetsEncryptClient($privateKey);
            $letsEncryptClient->setDryRun(true);
            $letsEncryptClient->registerAccount();
            $certificateOrder = $letsEncryptClient->requestOrder($domains);
            $siteUpdater = $this->getSiteUpdater($site);
            $siteUpdater->deleteLetsEncryptChallengeDirectory();
            $siteUpdater->createLetsEncryptChallengeFiles($certificateOrder);
            $validationErrors = $letsEncryptClient->validateDomains($certificateOrder);
            if (true === empty($validationErrors)) {
                $letsEncryptClient = new LetsEncryptClient($privateKey);
                $letsEncryptClient->registerAccount();
                $certificateOrder = $letsEncryptClient->requestOrder($domains);
                $siteUpdater = $this->getSiteUpdater($site);
                $siteUpdater->deleteLetsEncryptChallengeDirectory();
                $siteUpdater->createLetsEncryptChallengeFiles($certificateOrder);
                $validationErrors = $letsEncryptClient->validateDomains($certificateOrder);
            }
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
            $certificateEntity = $this->certificateEntityManager->createEntity();
            $certificateEntity->setType(CertificateEntity::TYPE_LETS_ENCRYPT);
            $certificateEntity->setSite($siteEntity);
            $certificateEntity->setCsr($certificate->getCsr());
            $certificateEntity->setPrivateKey($certificate->getPrivateKey());
            $certificateEntity->setCertificate($certificate->getCertificate());
            $certificateEntity->setCertificateChain($certificate->getCertificateChain());
            $siteUpdater->installCertificate($certificateEntity);
            $siteEntity->setCertificate($certificateEntity);
            $siteEntity->addCertificate($certificateEntity);
            $this->siteEntityManager->updateEntity($siteEntity);
            $output->writeln(sprintf("<info>%s</info>", "Certificate installation was successful."));
            return SiteCommand::SUCCESS;
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
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        } finally {
            if (true === isset($siteUpdater)) {
                $siteUpdater->deleteLetsEncryptChallengeDirectory();
            }
        }
    }
}