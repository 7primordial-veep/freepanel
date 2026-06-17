<?php

namespace App\Site\Nginx\Vhost\Processor;

class NginxErrorLog extends Processor
{
    protected string $placeholder = "{{nginx_error_log}}";
    public function process(string $content) : string
    {
        $siteUser = $this->site->getUser();
        $placeholderValue = rtrim(sprintf("error_log /home/%s/logs/nginx/error.log;", $siteUser), "/");
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}