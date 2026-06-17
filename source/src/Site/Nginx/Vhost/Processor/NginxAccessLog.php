<?php

namespace App\Site\Nginx\Vhost\Processor;

class NginxAccessLog extends Processor
{
    protected string $placeholder = "{{nginx_access_log}}";
    public function process(string $content) : string
    {
        $siteUser = $this->site->getUser();
        $allowTrafficFromCloudflareOnly = $this->site->allowTrafficFromCloudflareOnly();
        $placeholderValue = rtrim(sprintf("access_log /home/%s/logs/nginx/access.log %s;", $siteUser, true === $allowTrafficFromCloudflareOnly ? "cloudflare" : "main"), "/");
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}