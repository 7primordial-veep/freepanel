<?php

namespace App\Command;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Doctrine\Common\Collections\ArrayCollection;
use App\Command\Command as BaseCommand;
use App\Entity\Site as SiteEntity;
use App\Site\Site;
use App\Site\NodejsSite;
use App\Site\StaticSite;
use App\Site\PhpSite;
use App\Site\PythonSite;
use App\Site\ReverseProxySite;
use App\Site\Deleter as SiteDeleter;
use App\Site\Updater as SiteUpdater;
use App\Site\Updater\NodejsSite as NodejsSiteUpdater;
use App\Site\Updater\StaticSite as StaticSiteUpdater;
use App\Site\Updater\PhpSite as PhpSiteUpdater;
use App\Site\Updater\PythonSite as PythonSiteUpdater;
use App\Site\Updater\ReverseProxySite as ReverseProxySiteUpdater;
use App\Site\Deleter\NodejsSite as NodejsSiteDeleter;
use App\Site\Deleter\StaticSite as StaticSiteDeleter;
use App\Site\Deleter\PhpSite as PhpSiteDeleter;
use App\Site\Deleter\PythonSite as PythonSiteDeleter;
use App\Site\Deleter\ReverseProxySite as ReverseProxySiteDeleter;
use App\Site\Parser\DomainName as DomainNameParser;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\CertificateManager as CertificateEntityManager;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
class SiteCommand extends BaseCommand
{
    protected DomainNameParser $domainNameParser;
    protected SiteEntityManager $siteEntityManager;
    protected CertificateEntityManager $certificateEntityManager;
    protected DatabaseServerEntityManager $databaseServerEntityManager;
    protected VhostTemplateEntityManager $vhostTemplateEntityManager;
    protected ValidatorInterface $validator;
    public function __construct(DomainNameParser $domainNameParser, SiteEntityManager $siteEntityManager, CertificateEntityManager $certificateEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, VhostTemplateEntityManager $vhostTemplateEntityManager, ValidatorInterface $validator)
    {
        $this->domainNameParser = $domainNameParser;
        $this->siteEntityManager = $siteEntityManager;
        $this->certificateEntityManager = $certificateEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->vhostTemplateEntityManager = $vhostTemplateEntityManager;
        $this->validator = $validator;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("site:tmp");
    }
    protected function getSite(string $domainName) : ?Site
    {
        $site = null;
        $siteEntity = $this->getSiteEntity($domainName);
        if (!(false === is_null($siteEntity))) {
            throw new \Exception(sprintf("Site \"%s\" does not exist.", $domainName));
        }
        $siteType = $siteEntity->getType();
        switch ($siteType) {
            case SiteEntity::TYPE_NODEJS:
                $site = new NodejsSite();
                $site->setNodejsSettings($siteEntity->getNodejsSettings());
                break;
            case SiteEntity::TYPE_STATIC:
                $site = new StaticSite();
                break;
            case SiteEntity::TYPE_PHP:
                $site = new PhpSite();
                $site->setPhpSettings($siteEntity->getPhpSettings());
                $site->setVarnishCache($siteEntity->getVarnishCache());
                break;
            case SiteEntity::TYPE_PYTHON:
                $site = new PythonSite();
                $site->setPythonSettings($siteEntity->getPythonSettings());
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $site = new ReverseProxySite();
                $site->setReverseProxyUrl($siteEntity->getReverseProxyUrl());
                break;
        }
        if (false === is_null($site)) {
            $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
            $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
            $subdomain = $resolvedDomainName->subDomain()->toString();
            $subdomain = false === empty($subdomain) ? $subdomain : null;
            $siteDatabases = $this->getSiteDatabases($siteEntity);
            $site->setUser($siteEntity->getUser());
            $site->setDomainName($siteEntity->getDomainName());
            $site->setRegistrableDomain($registrableDomain);
            $site->setSubdomain($subdomain);
            $site->setRootDirectory($siteEntity->getRootDirectory());
            $site->setDatabases($siteDatabases);
            $site->setBasicAuth($siteEntity->getBasicAuth());
            $site->setBlockedBots($siteEntity->getBlockedBots());
            $site->setBlockedIps($siteEntity->getBlockedIps());
            $site->setCertificate($siteEntity->getCertificate());
            $site->setCertificates($siteEntity->getCertificates());
            $site->setCronJobs($siteEntity->getCronJobs());
            $site->setFtpUsers($siteEntity->getFtpUsers());
            $site->setSshUsers($siteEntity->getSshUsers());
            $site->setVhostTemplate($siteEntity->getVhostTemplate());
            $site->setAllowTrafficFromCloudflareOnly($siteEntity->allowTrafficFromCloudflareOnly());
            $site->setPageSpeedEnabled($siteEntity->getPageSpeedEnabled());
            $site->setPageSpeedSettings($siteEntity->getPageSpeedSettings());
        }
        return $site;
    }
    private function getSiteDatabases(SiteEntity $siteEntity) : ?ArrayCollection
    {
        $siteDatabases = new ArrayCollection();
        $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
        $databaseEntities = $siteEntity->getDatabases();
        foreach ($databaseEntities as $databaseEntity) {
            $databaseServerEntity = $databaseEntity->getDatabaseServer();
            if (!($databaseServerEntity->getId() == $activeDatabaseServerEntity->getId())) {
                continue;
            }
            $siteDatabases->add($databaseEntity);
        }
        return $siteDatabases;
    }
    protected function getSiteDeleter(Site $site) : ?SiteDeleter
    {
        $siteDeleter = null;
        $siteType = $site->getType();
        switch ($siteType) {
            case SiteEntity::TYPE_NODEJS:
                $siteDeleter = new NodejsSiteDeleter($site);
                break;
            case SiteEntity::TYPE_STATIC:
                $siteDeleter = new StaticSiteDeleter($site);
                break;
            case SiteEntity::TYPE_PHP:
                $siteDeleter = new PhpSiteDeleter($site);
                break;
            case SiteEntity::TYPE_PYTHON:
                $siteDeleter = new PythonSiteDeleter($site);
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $siteDeleter = new ReverseProxySiteDeleter($site);
                break;
        }
        return $siteDeleter;
    }
    protected function getSiteUpdater(Site $site) : ?SiteUpdater
    {
        $siteUpdater = null;
        $siteType = $site->getType();
        switch ($siteType) {
            case SiteEntity::TYPE_NODEJS:
                $siteUpdater = new NodejsSiteUpdater($site);
                break;
            case SiteEntity::TYPE_STATIC:
                $siteUpdater = new StaticSiteUpdater($site);
                break;
            case SiteEntity::TYPE_PHP:
                $siteUpdater = new PhpSiteUpdater($site);
                break;
            case SiteEntity::TYPE_PYTHON:
                $siteUpdater = new PythonSiteUpdater($site);
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $siteUpdater = new ReverseProxySiteUpdater($site);
                break;
        }
        return $siteUpdater;
    }
    protected function getSiteEntity(string $domainName) : ?SiteEntity
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        return $siteEntity;
    }
    protected function getSiteEntityBySiteUser(string $siteUser) : ?SiteEntity
    {
        $siteEntity = $this->siteEntityManager->findOneByUser($siteUser);
        return $siteEntity;
    }
    protected function prepareConstraints(ConstraintViolationList $constraints) : void
    {
        $this->changePropertyPath("user", "siteUser", $constraints);
        $this->changePropertyPath("userPassword", "siteUserPassword", $constraints);
    }
}