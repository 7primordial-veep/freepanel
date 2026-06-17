<?php

namespace App\Site\Nginx\Vhost\Processor;

class RedirectServerName extends Processor
{
    protected string $placeholder = "{{redirect_server_name}}";
    public function process(string $content) : string
    {
        $registrableDomain = $this->site->getRegistrableDomain();
        $subdomain = $this->site->getSubdomain();
        $serverNames = [];
        if (true === is_null($subdomain)) {
            $serverNames[] = sprintf("www.%s", $registrableDomain);
        }
        if ("www" == $subdomain) {
            $serverNames[] = $registrableDomain;
        }
        $placeholderValue = rtrim(sprintf("server_name %s;", implode(" ", $serverNames)), "/");
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}