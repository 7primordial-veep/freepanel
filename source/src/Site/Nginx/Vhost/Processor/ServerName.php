<?php

namespace App\Site\Nginx\Vhost\Processor;

class ServerName extends Processor
{
    protected string $placeholder = "{{server_name}}";
    public function process(string $content) : string
    {
        $registrableDomain = $this->site->getRegistrableDomain();
        $subdomain = $this->site->getSubdomain();
        $serverNames = [];
        if (true === is_null($subdomain)) {
            $serverNames[] = $registrableDomain;
        } else {
            $serverNames[] = sprintf("%s.%s", $subdomain, $registrableDomain);
        }
        if (true === is_null($subdomain) || "www" == $subdomain) {
            $serverNames[] = sprintf("www1.%s", $registrableDomain);
        }
        $placeholderValue = rtrim(sprintf("server_name %s;", implode(" ", $serverNames)), "/");
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}