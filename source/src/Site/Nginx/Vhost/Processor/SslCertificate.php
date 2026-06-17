<?php

namespace App\Site\Nginx\Vhost\Processor;

class SslCertificate extends Processor
{
    public const NGINX_SSL_CERTIFICATES_DIRECTORY = "/etc/nginx/ssl-certificates/";
    protected string $placeholder = "{{ssl_certificate}}";
    public function process(string $content) : string
    {
        $domainName = $this->site->getDomainName();
        $certificateFile = sprintf("%s/%s.crt", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $placeholderValue = sprintf("ssl_certificate %s;", $certificateFile);
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}