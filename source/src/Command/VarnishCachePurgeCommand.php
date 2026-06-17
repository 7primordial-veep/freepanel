<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Site as SiteEntity;
use App\Site\VarnishCache\Client as VarnishCacheClient;
class VarnishCachePurgeCommand extends SiteCommand
{
    protected function configure() : void
    {
        $this->setName("varnish-cache:purge");
        $this->setDescription("clpctl varnish-cache:purge --purge=all or --purge='tag1,tag2' or --purge='https://www.domain.com/site.html'");
        $this->addOption("purge", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $purge = trim($input->getOption("purge"));
            $systemUserName = $_SERVER["SUDO_USER"] ?? '';
            if (false === empty($systemUserName)) {
                $siteEntity = $this->getSiteEntityBySiteUser($systemUserName);
                if (false === is_null($siteEntity)) {
                    $domainName = $siteEntity->getDomainName();
                    $site = $this->getSite($domainName);
                    $siteType = $siteEntity->getType();
                    if (SiteEntity::TYPE_PHP == $siteType) {
                        $varnishCacheSettings = $site->getVarnishCacheSettings();
                        if (false === empty($varnishCacheSettings)) {
                            $varnishCacheClient = new VarnishCacheClient();
                            $varnishCacheClient->setServer($varnishCacheSettings["server"]);
                            if ("all" == $purge) {
                                $cacheTagPrefix = $varnishCacheSettings["cacheTagPrefix"];
                                $varnishCacheClient->purgeTag($cacheTagPrefix);
                                $varnishCacheClient->purgeHost($domainName);
                            } else {
                                $purgeValues = array_map("trim", array_filter(explode(",", trim($purge))));
                                foreach ($purgeValues as $purgeValue) {
                                    $purgeValue = trim($purgeValue);
                                    if (!(false === empty($purgeValue))) {
                                        continue;
                                    }
                                    if (!(true === str_starts_with($purgeValue, "http"))) {
                                        $varnishCacheClient->purgeTag($purgeValue);
                                        continue;
                                    }
                                    $varnishCacheClient->purgeUrl($purgeValue);
                                }
                            }
                            $output->writeln("<info>Varnish Cache has been purged.</info>");
                        }
                    }
                }
            }
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
}