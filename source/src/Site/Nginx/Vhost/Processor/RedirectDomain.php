<?php

namespace App\Site\Nginx\Vhost\Processor;

class RedirectDomain extends Processor
{
    protected string $placeholder = "{{redirect_domain}}";
    public function process(string $content) : string
    {
        $registrableDomain = $this->site->getRegistrableDomain();
        $subdomain = $this->site->getSubdomain();
        if (true === is_null($subdomain)) {
            $placeholderValue = $registrableDomain;
        } else {
            $placeholderValue = sprintf("www.%s", $registrableDomain);
        }
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}