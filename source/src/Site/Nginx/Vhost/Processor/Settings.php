<?php

namespace App\Site\Nginx\Vhost\Processor;

class Settings extends Processor
{
    private const NGINX_BASIC_AUTH_DIRECTORY = "/etc/nginx/basic-auth/";
    private const LETS_ENCRYPT_LOCATION = "location ~ /.well-known {\n    auth_basic off;\n    allow all;\n  }  ";
    private array $elements = [];
    protected string $placeholder = "{{settings}}";
    public function process(string $content) : string
    {
        $basicAuth = $this->site->getBasicAuth();
        $this->addBasicAuth();
        $this->addPageSpeed();
        $this->addCloudflareTrafficRestriction();
        if (true === is_null($basicAuth) || false === $basicAuth->getIsActive()) {
            $this->addBlockedBots();
            $this->addBlockedIps();
            $this->addBlockedCountries();
        }
        $placeholderValue = implode(str_repeat(PHP_EOL, 2), $this->elements);
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
    private function addLetsEncryptLocation() : void
    {
        $this->elements[] = self::LETS_ENCRYPT_LOCATION;
    }
    private function addBasicAuth() : void
    {
        $basicAuth = $this->site->getBasicAuth();
        if (false === is_null($basicAuth) && true === $basicAuth->getIsActive()) {
            $whitelistedIps = $basicAuth->getWhitelistedIps();
            $basicAuthFile = sprintf("%s/%s", rtrim(self::NGINX_BASIC_AUTH_DIRECTORY, "/"), $this->site->getDomainName());
            $basicAuthContent = '';
            if (false === empty($whitelistedIps)) {
                $whitelistedIps = explode(",", $whitelistedIps);
                if (count($whitelistedIps)) {
                    $basicAuthContent .= "  satisfy any;" . PHP_EOL;
                    foreach ($whitelistedIps as $ip) {
                        $basicAuthContent .= sprintf("  allow %s;", $ip) . PHP_EOL;
                    }
                    $basicAuthContent .= "  deny all;" . PHP_EOL;
                }
            }
            $basicAuthContent .= "  auth_basic \"Restricted Area\";" . PHP_EOL;
            $basicAuthContent .= sprintf("  auth_basic_user_file %s;", $basicAuthFile);
            $this->elements[] = $basicAuthContent;
        }
    }
    private function addPageSpeed() : void
    {
        $pageSpeedEnabled = $this->site->getPageSpeedEnabled();
        $pageSpeedSettings = $this->site->getPageSpeedSettings();
        $siteUser = $this->site->getUser();
        if (true === $pageSpeedEnabled && false === empty($pageSpeedSettings)) {
            $pageSpeedSettingsContent = sprintf("  pagespeed %s;", true === $pageSpeedEnabled ? "on" : "off") . PHP_EOL;
            $pageSpeedSettingsContent .= sprintf("  pagespeed FileCachePath \"/home/%s/tmp/pagespeed_cache/\";", $siteUser) . PHP_EOL;
            $pageSpeedSettingsExploded = explode(PHP_EOL, $pageSpeedSettings);
            if (false === empty($pageSpeedSettingsExploded) && true === is_array($pageSpeedSettingsExploded)) {
                foreach ($pageSpeedSettingsExploded as $setting) {
                    $setting = trim($setting);
                    if (!(false === empty($setting))) {
                        continue;
                    }
                    $pageSpeedSettingsContent .= sprintf("  %s", $setting) . PHP_EOL;
                }
            }
            $this->elements[] = $pageSpeedSettingsContent;
        }
    }
    private function addCloudflareTrafficRestriction() : void
    {
        $allowTrafficFromCloudflareOnly = $this->site->allowTrafficFromCloudflareOnly();
        if (true === $allowTrafficFromCloudflareOnly) {
            $this->elements[] = "  include /etc/nginx/cloudflare/ips;";
        }
    }
    private function addBlockedBots() : void
    {
        $blockedBots = $this->site->getBlockedBots();
        if (false === is_null($blockedBots) && count($blockedBots)) {
            $botsToBlock = [];
            foreach ($blockedBots as $blockedBot) {
                $blockedBotName = preg_replace("/[^a-z0-9 ._-]/", '', strtolower($blockedBot->getName()));
                if (true === empty($blockedBotName)) {
                    continue;
                }
                $botsToBlock[] = str_replace([" "], ["\\s"], $blockedBotName);
            }
            if (false === empty($botsToBlock)) {
                $botsToBlock = array_unique($botsToBlock);
                $blockedBotsContent = sprintf("  if (\$http_user_agent ~* (%s)) {\n    return 444;\n  }", implode("|", $botsToBlock));
                $this->elements[] = $blockedBotsContent;
            }
        }
    }
    private function addBlockedCountries() : void
    {
        $blockedCountries = method_exists($this->site, 'getBlockedCountriesArray') ? $this->site->getBlockedCountriesArray() : [];
        if (true === empty($blockedCountries)) {
            return;
        }
        $codes = [];
        foreach ($blockedCountries as $code) {
            $code = preg_replace('/[^A-Z]/', '', strtoupper((string) $code));
            if (2 === strlen($code)) {
                $codes[$code] = $code;
            }
        }
        if (true === empty($codes)) {
            return;
        }
        $pattern = implode('|', array_values($codes));
        $block = "  if (\$geoip_country_code ~ (" . $pattern . ")) {" . PHP_EOL;
        $block .= "    return 403;" . PHP_EOL;
        $block .= "  }";
        $this->elements[] = $block;
    }
    private function addBlockedIps() : void
    {
        $blockedIps = $this->site->getBlockedIps();
        if (false === is_null($blockedIps) && count($blockedIps)) {
            $ipsToBlock = [];
            foreach ($blockedIps as $blockedIp) {
                $ipsToBlock[] = $blockedIp->getIp();
            }
            if (false === empty($ipsToBlock)) {
                $ipsToBlock = array_unique($ipsToBlock);
                $allowTrafficFromCloudflareOnly = $this->site->allowTrafficFromCloudflareOnly();
                if (true === $allowTrafficFromCloudflareOnly) {
                    $blockedIpsContent = sprintf("  if (\$http_cf_connecting_ip ~ \"^(%s)\$\") {", implode("|", $ipsToBlock)) . PHP_EOL;
                    $blockedIpsContent .= "    return 403;" . PHP_EOL;
                    $blockedIpsContent .= "  }";
                } else {
                    $blockedIpsContent = sprintf("  if (\$remote_addr ~ \"^(%s)\$\") {", implode("|", $ipsToBlock)) . PHP_EOL;
                    $blockedIpsContent .= "    return 403;" . PHP_EOL;
                    $blockedIpsContent .= "  }";
                }
                $this->elements[] = $blockedIpsContent;
            }
        }
    }
}