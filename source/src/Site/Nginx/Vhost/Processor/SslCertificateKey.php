<?php

namespace App\Site\Nginx\Vhost\Processor;

class SslCertificateKey extends Processor
{
    public const NGINX_SSL_CERTIFICATES_DIRECTORY = "/etc/nginx/ssl-certificates/";
    protected string $placeholder = "{{ssl_certificate_key}}";
    public function process(string $content) : string
    {
        $domainName = $this->site->getDomainName();
        $privateKeyFile = sprintf("%s/%s.key", rtrim(self::NGINX_SSL_CERTIFICATES_DIRECTORY, "/"), $domainName);
        $placeholderValue = sprintf("ssl_certificate_key %s;", $privateKeyFile);
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}