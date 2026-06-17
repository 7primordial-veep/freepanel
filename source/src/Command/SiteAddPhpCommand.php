<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Site as SiteEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\PhpSettings as PhpSettingsEntity;
use App\Site\PhpSite;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\Util\Openssl;
use App\Site\Nginx\Vhost\PhpTemplate as PhpVhostTemplate;
use App\Site\Creator\PhpSite as PhpSiteCreator;
use App\Site\Nginx\Vhost\Processor\ServerName as ServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectServerName as RedirectServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectDomain as RedirectDomainProcessor;
class SiteAddPhpCommand extends SiteCommand
{
    private const VARNISH_CACHE_SERVER = "127.0.0.1:6081";
    protected function configure() : void
    {
        $this->setName("site:add:php");
        $this->setDescription("clpctl site:add:php --domainName=www.domain.com --phpVersion=8.3 --vhostTemplate='Generic' --siteUser=john --siteUserPassword='!secretPassword!'");
        $this->setComment("Adding a PHP Site");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("phpVersion", null, InputOption::VALUE_REQUIRED);
        $this->addOption("vhostTemplate", null, InputOption::VALUE_REQUIRED);
        $this->addOption("siteUser", null, InputOption::VALUE_REQUIRED);
        $this->addOption("siteUserPassword", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $domainName = mb_strtolower(trim($input->getOption("domainName")));
            $rootDirectory = $domainName;
            $phpVersion = trim($input->getOption("phpVersion"));
            $vhostTemplate = trim($input->getOption("vhostTemplate"));
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
            $vhostTemplateRootDirectory = $vhostTemplateEntity->getRootDirectory();
            if (false === empty($vhostTemplateRootDirectory)) {
                $rootDirectory = sprintf("%s/%s", $rootDirectory, ltrim(rtrim($vhostTemplateRootDirectory, "/"), "/"));
            }
            $varnishCacheSettings = [];
            if (false === empty($vhostTemplateEntity->getVarnishCacheSettings())) {
                $varnishCacheSettings = (array) json_decode($vhostTemplateEntity->getVarnishCacheSettings(), true);
            }
            $varnishCache = false === empty($varnishCacheSettings) ? true : false;
            $siteEntity = $this->siteEntityManager->createEntity();
            $siteEntity->setType(SiteEntity::TYPE_PHP);
            $siteEntity->setDomainName($domainName);
            $siteEntity->setRootDirectory($rootDirectory);
            $siteEntity->setUser($siteUser);
            $siteEntity->setUserPassword($siteUserPassword);
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
            if (false === empty($varnishCache)) {
                $siteEntity->setVarnishCache(true);
            }
            $siteEntity->setVhostTemplate($vhostTemplate);
            $phpSettingsEntity = new PhpSettingsEntity();
            $siteEntity->setPhpSettings($phpSettingsEntity);
            $phpSettingsEntity->setPhpVersion($phpVersion);
            $phpSettingsEntity->setSite($siteEntity);
            $siteConstraints = $this->validator->validate($siteEntity);
            $phpSettingsConstraints = $this->validator->validate($phpSettingsEntity);
            if (!(0 == count($siteConstraints) && 0 == count($phpSettingsConstraints))) {
                $constraints = new ConstraintViolationList();
                $constraints->addAll($siteConstraints);
                $constraints->addAll($phpSettingsConstraints);
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
            $phpSite = new PhpSite();
            $phpSite->setUser($siteEntity->getUser());
            $phpSite->setUserPassword($siteEntity->getUserPassword());
            $phpSite->setDomainName($domainName);
            $phpSite->setRegistrableDomain($registrableDomain);
            $phpSite->setSubdomain($subdomain);
            $phpSite->setRootDirectory($siteEntity->getRootDirectory());
            $phpSite->setCertificate($certificateEntity);
            $phpSite->setPhpSettings($phpSettingsEntity);
            $phpSite->setVhostTemplate($siteEntity->getVhostTemplate());
            $phpSiteCreator = new PhpSiteCreator($phpSite);
            $phpSiteCreator->createUser();
            $phpSiteCreator->createRootDirectory();
            $phpSiteCreator->createLogrotateFile();
            $phpSiteCreator->createIndexPhp();
            $phpSiteCreator->createPrivateKeyAndCertificate();
            $phpSiteCreator->createPhpFpmPool();
            $phpSiteCreator->reloadPhpFpmService();
            if (true === $varnishCache) {
                $phpSite->setVarnishCache(true);
                $defaultVarnishCacheSettings = ["enabled" => false, "server" => self::VARNISH_CACHE_SERVER, "cacheTagPrefix" => substr(md5(time()), 0, 4)];
                $varnishCacheSettings = array_merge($defaultVarnishCacheSettings, $varnishCacheSettings);
                $phpSiteCreator->createVarnishCacheStructure($varnishCacheSettings);
                $phpSite->setVarnishCacheSettings($varnishCacheSettings);
            }
            $phpSiteCreator->createNginxVhost();
            $phpSiteCreator->reloadNginxService();
            $phpSiteCreator->resetPermissions();
            $vhostTemplate = new PhpVhostTemplate($phpSite);
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