<?php

namespace App\Site\Nginx\Vhost\Processor;

class ReverseProxyUrl extends Processor
{
    protected string $placeholder = "{{reverse_proxy_url}}";
    public function process(string $content) : string
    {
        $placeholderValue = $this->site->getReverseProxyUrl();
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}