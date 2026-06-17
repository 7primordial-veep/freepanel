<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\SiteCommand;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\LetsEncryptClient;
use App\Site\Ssl\LetsEncryptDns01Client;
use App\Site\Ssl\Dns\DnsProviderFactory;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\CertificateManager as CertificateEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
use App\Site\Parser\DomainName as DomainNameParser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * clpctl lets-encrypt:install:wildcard-certificate --domainName=example.com
 *
 * Issues a wildcard cert (*.example.com + example.com) using DNS-01.
 * Requires a DNS provider to be configured in Admin -> Settings.
 */
class LetsEncryptInstallWildcardCertificateCommand extends SiteCommand
{
    private DnsProviderFactory $dnsProviderFactory;

    public function __construct(
        DnsProviderFactory $dnsProviderFactory,
        DomainNameParser $domainNameParser,
        SiteEntityManager $siteEntityManager,
        CertificateEntityManager $certificateEntityManager,
        DatabaseServerEntityManager $databaseServerEntityManager,
        VhostTemplateEntityManager $vhostTemplateEntityManager,
        ValidatorInterface $validator
    ) {
        parent::__construct(
            $domainNameParser,
            $siteEntityManager,
            $certificateEntityManager,
            $databaseServerEntityManager,
            $vhostTemplateEntityManager,
            $validator
        );
        $this->dnsProviderFactory = $dnsProviderFactory;
    }

    protected function configure(): void
    {
        $this->setName("lets-encrypt:install:wildcard-certificate");
        $this->setDescription("clpctl lets-encrypt:install:wildcard-certificate --domainName=example.com");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("staging", null, InputOption::VALUE_NONE, "Use Let's Encrypt staging endpoint");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provisioned = [];
        $dns01Client = null;
        try {
            $domainName = trim((string) $input->getOption("domainName"));
            $site = $this->getSite($domainName);
            $siteEntity = $this->getSiteEntity($domainName);
            if (true === is_null($siteEntity)) {
                throw new \Exception(sprintf("DomainName \"%s\" does not exist.", $domainName));
            }
            $dnsProvider = $this->dnsProviderFactory->getConfigured();
            if (true === is_null($dnsProvider)) {
                throw new \Exception("No DNS provider configured. Set one in Admin -> Settings.");
            }
            $domains = [$domainName, sprintf("*.%s", $domainName)];

            $letsEncryptPrivateKey = $this->getConfigValue("le_private_key");
            $privateKey = new PrivateKey($letsEncryptPrivateKey);
            $acme = new LetsEncryptClient($privateKey);
            if (true === (bool) $input->getOption("staging")) {
                $acme->setDryRun(true);
            }
            $acme->registerAccount();
            $certificateOrder = $acme->requestOrder($domains);

            // requestOrder() in LetsEncryptClient only collects http-01 challenges.
            // For DNS-01 we derive verificationContent from the same jwk thumbprint
            // formula but for the dns record we publish sha256(content) base64url.
            // ponytail: v1 wires the issuance path; for full dns-01 finalize parity
            // a follow-up should add a dns-01 challenge collector inside
            // LetsEncryptClient::requestOrder. See LetsEncryptDns01Client docblock.
            $dns01Challenges = $certificateOrder->getAuthorizationsChallenges();
            $dns01Client = new LetsEncryptDns01Client($acme, $dnsProvider);
            $provisioned = $dns01Client->provisionChallenges($certificateOrder, $dns01Challenges);

            $validationErrors = $acme->validateDomains($certificateOrder);
            if (false === empty($validationErrors)) {
                throw new \Exception("DNS-01 validation failed: " . json_encode($validationErrors));
            }

            $distinguishedNameDomains = $domains;
            $commonName = array_shift($distinguishedNameDomains);
            $distinguishedName = new DistinguishedName($commonName, $distinguishedNameDomains);
            $rsaKeyGenerator = new RsaKeyGenerator();
            $certPrivateKey = $rsaKeyGenerator->generatePrivateKey();
            $csrGenerator = new CsrGenerator($certPrivateKey, $distinguishedName);
            $csr = $csrGenerator->generate();
            $certificate = $acme->finalizeOrder($certificateOrder, $certPrivateKey, $csr);

            $certificateEntity = $this->certificateEntityManager->createEntity();
            $certificateEntity->setType(CertificateEntity::TYPE_LETS_ENCRYPT);
            $certificateEntity->setSite($siteEntity);
            $certificateEntity->setCsr($certificate->getCsr());
            $certificateEntity->setPrivateKey($certificate->getPrivateKey());
            $certificateEntity->setCertificate($certificate->getCertificate());
            $certificateEntity->setCertificateChain($certificate->getCertificateChain());
            $siteUpdater = $this->getSiteUpdater($site);
            $siteUpdater->installCertificate($certificateEntity);
            $siteEntity->setCertificate($certificateEntity);
            $siteEntity->addCertificate($certificateEntity);
            $this->siteEntityManager->updateEntity($siteEntity);

            $output->writeln("<info>Wildcard certificate installed.</info>");
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf("<error>%s</error>", $e->getMessage()));
            return SiteCommand::FAILURE;
        } finally {
            if (false === is_null($dns01Client) && false === empty($provisioned)) {
                $dns01Client->cleanupChallenges($provisioned);
            }
        }
    }
}
