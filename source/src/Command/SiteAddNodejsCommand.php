<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Site as SiteEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\NodejsSettings as NodejsSettingsEntity;
use App\Site\NodejsSite;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\Util\Openssl;
use App\Site\Nginx\Vhost\NodejsTemplate as NodejsVhostTemplate;
use App\Site\Creator\NodejsSite as NodejsSiteCreator;
use App\Site\Nginx\Vhost\Processor\ServerName as ServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectServerName as RedirectServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectDomain as RedirectDomainProcessor;
class SiteAddNodejsCommand extends SiteCommand
{
    private const VHOST_TEMPLATE_NAME = "Nodejs";
    protected function configure() : void
    {
        $this->setName("site:add:nodejs");
        $this->setDescription("clpctl site:add:nodejs --domainName=www.domain.com --nodejsVersion=22 --appPort=3000 --siteUser=john --siteUserPassword='!secretPassword!'");
        $this->setComment("Adding a Node.js Site");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("nodejsVersion", null, InputOption::VALUE_REQUIRED);
        $this->addOption("appPort", null, InputOption::VALUE_REQUIRED);
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
            $nodejsVersion = (int) $input->getOption("nodejsVersion");
            $appPort = (int) $input->getOption("appPort");
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
            $siteEntity->setType(SiteEntity::TYPE_NODEJS);
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
            $siteEntity->setVhostTemplate($vhostTemplate);
            $nodejsSettingsEntity = new NodejsSettingsEntity();
            $siteEntity->setNodejsSettings($nodejsSettingsEntity);
            $nodejsSettingsEntity->setNodejsVersion($nodejsVersion);
            $nodejsSettingsEntity->setPort($appPort);
            $nodejsSettingsEntity->setSite($siteEntity);
            $siteConstraints = $this->validator->validate($siteEntity);
            $nodejsSettingsConstraints = $this->validator->validate($nodejsSettingsEntity);
            if (!(0 == count($siteConstraints) && 0 == count($nodejsSettingsConstraints))) {
                $constraints = new ConstraintViolationList();
                $constraints->addAll($siteConstraints);
                $constraints->addAll($nodejsSettingsConstraints);
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
            $nodejsSite = new NodejsSite();
            $nodejsSite->setUser($siteEntity->getUser());
            $nodejsSite->setUserPassword($siteEntity->getUserPassword());
            $nodejsSite->setDomainName($domainName);
            $nodejsSite->setRegistrableDomain($registrableDomain);
            $nodejsSite->setSubdomain($subdomain);
            $nodejsSite->setRootDirectory($siteEntity->getRootDirectory());
            $nodejsSite->setNodejsSettings($nodejsSettingsEntity);
            $nodejsSite->setCertificate($certificateEntity);
            $nodejsSite->setVhostTemplate($siteEntity->getVhostTemplate());
            $nodejsSiteCreator = new NodejsSiteCreator($nodejsSite);
            $nodejsSiteCreator->createUser();
            $nodejsSiteCreator->createRootDirectory();
            $nodejsSiteCreator->createLogrotateFile();
            $nodejsSiteCreator->createNvmDirectory();
            $nodejsSiteCreator->installNodejs();
            $nodejsSiteCreator->createPrivateKeyAndCertificate();
            $nodejsSiteCreator->createNginxVhost();
            $nodejsSiteCreator->reloadNginxService();
            $nodejsSiteCreator->resetPermissions();
            $vhostTemplate = new NodejsVhostTemplate($nodejsSite);
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