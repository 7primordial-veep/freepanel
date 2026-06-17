<?php

namespace App\Site\Nginx\Vhost\Processor;

class VarnishProxyPass extends Processor
{
    private const DEFAULT_PROXY_PASS_VALUE = "http://127.0.0.1:8080";
    protected string $placeholder = "{{varnish_proxy_pass}}";
    public function process(string $content) : string
    {
        $varnishCache = $this->site->getVarnishCache();
        if (true === $varnishCache) {
            $varnishCacheSettings = $this->site->getVarnishCacheSettings();
            $proxyPassValue = self::DEFAULT_PROXY_PASS_VALUE;
            if (true === isset($varnishCacheSettings["enabled"]) && true === $varnishCacheSettings["enabled"] && true === isset($varnishCacheSettings["server"])) {
                $varnishServer = rtrim(ltrim((string) $varnishCacheSettings["server"], "/"), "/");
                if (false === empty($varnishServer) && 1 === preg_match("/^[A-Za-z0-9.:_\\-\\/]+\$/", $varnishServer)) {
                    $proxyPassValue = sprintf("http://%s", $varnishServer);
                }
            }
            $placeholderValue = sprintf("proxy_pass %s;", $proxyPassValue);
            $content = $this->replace($placeholderValue, $content);
        }
        return $content;
    }
}