<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Site as SiteEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\PythonSettings as PythonSettingsEntity;
use App\Site\PythonSite;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\Util\Openssl;
use App\Site\Nginx\Vhost\PythonTemplate as PythonVhostTemplate;
use App\Site\Creator\PythonSite as PythonSiteCreator;
use App\Site\Nginx\Vhost\Processor\ServerName as ServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectServerName as RedirectServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectDomain as RedirectDomainProcessor;
class SiteAddPythonCommand extends SiteCommand
{
    private const VHOST_TEMPLATE_NAME = "Python";
    protected function configure() : void
    {
        $this->setName("site:add:python");
        $this->setDescription("clpctl site:add:python --domainName=www.domain.com --pythonVersion=3.12 --appPort=8080 --siteUser=john --siteUserPassword='!secretPassword!'");
        $this->setComment("Adding a Python Site");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("pythonVersion", null, InputOption::VALUE_REQUIRED);
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
            $pythonVersion = $input->getOption("pythonVersion");
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
            $siteEntity->setType(SiteEntity::TYPE_PYTHON);
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
            $pythonSettingsEntity = new PythonSettingsEntity();
            $siteEntity->setPythonSettings($pythonSettingsEntity);
            $pythonSettingsEntity->setPythonVersion($pythonVersion);
            $pythonSettingsEntity->setPort($appPort);
            $pythonSettingsEntity->setSite($siteEntity);
            $siteConstraints = $this->validator->validate($siteEntity);
            $pythonSettingsConstraints = $this->validator->validate($pythonSettingsEntity);
            if (!(0 == count($siteConstraints) && 0 == count($pythonSettingsConstraints))) {
                $constraints = new ConstraintViolationList();
                $constraints->addAll($siteConstraints);
                $constraints->addAll($pythonSettingsConstraints);
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
            $pythonSite = new PythonSite();
            $pythonSite->setUser($siteEntity->getUser());
            $pythonSite->setUserPassword($siteEntity->getUserPassword());
            $pythonSite->setDomainName($domainName);
            $pythonSite->setRegistrableDomain($registrableDomain);
            $pythonSite->setSubdomain($subdomain);
            $pythonSite->setRootDirectory($siteEntity->getRootDirectory());
            $pythonSite->setPythonSettings($pythonSettingsEntity);
            $pythonSite->setCertificate($certificateEntity);
            $pythonSite->setVhostTemplate($siteEntity->getVhostTemplate());
            $pythonSiteCreator = new PythonSiteCreator($pythonSite);
            $pythonSiteCreator->createUser();
            $pythonSiteCreator->createRootDirectory();
            $pythonSiteCreator->createLogrotateFile();
            $pythonSiteCreator->writePythonVersionFile();
            $pythonSiteCreator->createPrivateKeyAndCertificate();
            $pythonSiteCreator->createNginxVhost();
            $pythonSiteCreator->reloadNginxService();
            $pythonSiteCreator->resetPermissions();
            $vhostTemplate = new PythonVhostTemplate($pythonSite);
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