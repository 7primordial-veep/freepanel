<?php

namespace App\Site\Nginx\Vhost;

use App\Site\Site;
use App\Site\Nginx\Vhost\Processor\Processor;
use App\Site\Nginx\Vhost\Processor\RootDirectory as RootDirectoryProcessor;
use App\Site\Nginx\Vhost\Processor\ServerName as ServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectServerName as RedirectServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectDomain as RedirectDomainProcessor;
use App\Site\Nginx\Vhost\Processor\NginxAccessLog as NginxAccessLogProcessor;
use App\Site\Nginx\Vhost\Processor\NginxErrorLog as NginxErrorLogProcessor;
use App\Site\Nginx\Vhost\Processor\SslCertificateKey as NginxCertificateKeyProcessor;
use App\Site\Nginx\Vhost\Processor\SslCertificate as NginxCertificateProcessor;
use App\Site\Nginx\Vhost\Processor\Settings as SettingsProcessor;
use App\Site\Nginx\Vhost\Processor\SecurityHeaders as SecurityHeadersProcessor;
class Template
{
    protected Site $site;
    protected ?string $content = null;
    protected array $placeholders = [];
    protected array $processors = [];
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->init();
    }
    protected function init() : void
    {
        $rootDirectoryProcessor = new RootDirectoryProcessor();
        $serverNameProcessor = new ServerNameProcessor();
        $redirectServerNameProcessor = new RedirectServerNameProcessor();
        $redirectDomainProcessor = new RedirectDomainProcessor();
        $nginxAccessLogProcessor = new NginxAccessLogProcessor();
        $nginxErrorLogProcessor = new NginxErrorLogProcessor();
        $certificateKeyProcessor = new NginxCertificateKeyProcessor();
        $certificateProcessor = new NginxCertificateProcessor();
        $settingsProcessor = new SettingsProcessor();
        $securityHeadersProcessor = new SecurityHeadersProcessor();
        $this->addProcessor($securityHeadersProcessor);
        $this->addProcessor($rootDirectoryProcessor);
        $this->addProcessor($serverNameProcessor);
        $this->addProcessor($redirectServerNameProcessor);
        $this->addProcessor($redirectDomainProcessor);
        $this->addProcessor($nginxAccessLogProcessor);
        $this->addProcessor($nginxErrorLogProcessor);
        $this->addProcessor($certificateKeyProcessor);
        $this->addProcessor($certificateProcessor);
        $this->addProcessor($settingsProcessor);
    }
    public function getSite() : Site
    {
        return $this->site;
    }
    public function setContent(string $content) : void
    {
        $this->content = $content;
    }
    public function getContent() : ?string
    {
        return $this->content;
    }
    public function build() : void
    {
        $processors = $this->getProcessors();
        $placeholders = $this->getPlaceholders();
        foreach ($placeholders as $placeholder) {
            $hasProcessor = $this->hasProcessor($placeholder);
            if (!(true === $hasProcessor)) {
                continue;
            }
            $processor = $processors[$placeholder];
            $processor->setSite($this->site);
            $this->content = $processor->process($this->content);
            $this->removePlaceholder($placeholder);
        }
    }
    public function removeEmptyPlaceholders() : void
    {
        $placeholders = $this->getPlaceholders();
        foreach ($placeholders as $placeholder) {
            $this->content = str_replace($placeholder, '', $this->content);
        }
    }
    protected function hasProcessor(string $placeholder) : bool
    {
        return isset($this->processors[$placeholder]);
    }
    protected function getProcessors() : array
    {
        return $this->processors;
    }
    public function addProcessor(Processor $processor) : void
    {
        $this->processors[$processor->getPlaceholder()] = $processor;
    }
    public function resetProcessors() : void
    {
        $this->processors = [];
    }
    public function getPlaceholders() : array
    {
        if (true === empty($this->placeholders)) {
            preg_match_all("/[{{]{2}([\\sa-zA-Z0-9_]+)[}}]{2}/", $this->content, $placeholders);
            if (true === isset($placeholders[0]) && count($placeholders[0])) {
                $placeholders = $placeholders[0];
                foreach ($placeholders as $placeholder) {
                    $placeholder = trim($placeholder);
                    if (!(false === empty($placeholder))) {
                        continue;
                    }
                    $this->addPlaceholder($placeholder);
                }
            }
        }
        return $this->placeholders;
    }
    private function addPlaceholder(string $placeholder) : void
    {
        $this->placeholders[$placeholder] = $placeholder;
    }
    private function removePlaceholder(string $placeholder) : void
    {
        if (true === isset($this->placeholders[$placeholder])) {
            unset($this->placeholders[$placeholder]);
        }
    }
}