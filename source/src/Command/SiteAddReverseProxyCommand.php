<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Site as SiteEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Site\ReverseProxySite;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\Util\Openssl;
use App\Site\Nginx\Vhost\ReverseProxyTemplate as ReverseProxyVhostTemplate;
use App\Site\Creator\ReverseProxySite as ReverseProxySiteCreator;
use App\Site\Nginx\Vhost\Processor\ServerName as ServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectServerName as RedirectServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectDomain as RedirectDomainProcessor;
class SiteAddReverseProxyCommand extends SiteCommand
{
    private const VHOST_TEMPLATE_NAME = "ReverseProxy";
    protected function configure() : void
    {
        $this->setName("site:add:reverse-proxy");
        $this->setDescription("clpctl site:add:reverse-proxy --domainName=www.domain.com --reverseProxyUrl='http://127.0.0.1:8000' --siteUser=john --siteUserPassword='!secretPassword!'");
        $this->setComment("Adding a Reverse Proxy");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("reverseProxyUrl", null, InputOption::VALUE_REQUIRED);
        $this->addOption("siteUser", null, InputOption::VALUE_REQUIRED);
        $this->addOption("siteUserPassword", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $domainName = mb_strtolower(trim($input->getOption("domainName")));
            $vhostTemplate = self::VHOST_TEMPLATE_NAME;
            $rootDirectory = $domainName;
            $reverseProxyUrl = trim($input->getOption("reverseProxyUrl"));
            $siteUser = trim($input->getOption("siteUser"));
            $siteUserPassword = trim($input->getOption("siteUserPassword"));
            $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
            $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
            $subdomain = $resolvedDomainName->subDomain()->toString();
            $subdomain = false === empty($subdomain) ? $subdomain : null;
            $vhostTemplateEntity = $this->vhostTemplateEntityManager->findOneByName($vhostTemplate);
            if (!(false === is_null($vhostTemplateEntity))) {
                throw new \Exception(sprintf("Vhost Template \"%s\" does not exist.", $vhostTemplate));
            }
            $vhostTemplate = $vhostTemplateEntity->getTemplate();
            $siteEntity = $this->siteEntityManager->createEntity();
            $siteEntity->setType(SiteEntity::TYPE_REVERSE_PROXY);
            $siteEntity->setDomainName($domainName);
            $siteEntity->setUser($siteUser);
            $siteEntity->setUserPassword($siteUserPassword);
            $siteEntity->setRootDirectory($rootDirectory);
            $siteEntity->setReverseProxyUrl($reverseProxyUrl);
            if (true === is_null($subdomain) || "www" == $subdomain) {
                if (true === isset($_ENV["APP_HTTP3"]) && "true" === $_ENV["APP_HTTP3"]) {
                    $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../resources/nginx/vhost_template/redirect-http3");
                } else {
                    $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../resources/nginx/vhost_template/redirect");
                }
                $redirectionVhostTemplate = file_get_contents($redirectionVhostTemplateFile);
                if (false === empty($redirectionVhostTemplate)) {
                    $vhostTemplate = sprintf("%s%s", $redirectionVhostTemplate, $vhostTemplate);
                }
            }
            $siteEntity->setVhostTemplate($vhostTemplate);
            $siteConstraints = $this->validator->validate($siteEntity);
            if (!(0 == count($siteConstraints))) {
                $constraints = new ConstraintViolationList();
                $constraints->addAll($siteConstraints);
                return $this->renderConstraints($constraints, $output);
            }
            $certificateEntity = $this->certificateEntityManager->createEntity();
            $certificateEntity->setSite($siteEntity);
            $rsaKeyGenerator = new RsaKeyGenerator();
            $privateKey = $rsaKeyGenerator->generatePrivateKey();
            $subjectAlternativeNames = [];
            if (true === is_null($subdomain)) {
                $subjectAlternativeNames[] = sprintf("www.%s", $domainName);
            }
            if (false === is_null($subdomain) && "www" == $subdomain) {
                $subjectAlternativeNames[] = $registrableDomain;
            }
            $distinguishedName = new DistinguishedName($domainName, $subjectAlternativeNames);
            $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
            $csr = $csrGenerator->generate();
            $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
            $certificateEntity->setDefaultCertificate(true);
            $certificateEntity->setType(CertificateEntity::TYPE_SELF_SIGNED);
            $certificateEntity->setCsr($csr);
            $certificateEntity->setPrivateKey($privateKey->getPEM());
            $certificateEntity->setCertificate($selfSignedCertificate);
            $siteEntity->setCertificate($certificateEntity);
            $reverseProxySite = new ReverseProxySite();
            $reverseProxySite->setUser($siteEntity->getUser());
            $reverseProxySite->setUserPassword($siteEntity->getUserPassword());
            $reverseProxySite->setDomainName($domainName);
            $reverseProxySite->setRegistrableDomain($registrableDomain);
            $reverseProxySite->setSubdomain($subdomain);
            $reverseProxySite->setRootDirectory($siteEntity->getRootDirectory());
            $reverseProxySite->setCertificate($certificateEntity);
            $reverseProxySite->setReverseProxyUrl($reverseProxyUrl);
            $reverseProxySite->setVhostTemplate($siteEntity->getVhostTemplate());
            $reversProxySiteCreator = new ReverseProxySiteCreator($reverseProxySite);
            $reversProxySiteCreator->createUser();
            $reversProxySiteCreator->createRootDirectory();
            $reversProxySiteCreator->createLogrotateFile();
            $reversProxySiteCreator->createPrivateKeyAndCertificate();
            $reversProxySiteCreator->createNginxVhost();
            $reversProxySiteCreator->reloadNginxService();
            $reversProxySiteCreator->resetPermissions();
            $vhostTemplate = new ReverseProxyVhostTemplate($reverseProxySite);
            $vhostTemplate->setContent($siteEntity->getVhostTemplate());
            $vhostTemplate->resetProcessors();
            $vhostTemplate->addProcessor(new ServerNameProcessor());
            $vhostTemplate->addProcessor(new RedirectServerNameProcessor());
            $vhostTemplate->addProcessor(new RedirectDomainProcessor());
            $vhostTemplate->build();
            $siteEntity->setVhostTemplate($vhostTemplate->getContent());
            $siteEntity->setApplication($vhostTemplateEntity->getName());
            $this->siteEntityManager->updateEntity($siteEntity);
            $output->writeln(sprintf("<info>Site</info> <comment>%s</comment> <info>has been added.</info>", $domainName));
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
}