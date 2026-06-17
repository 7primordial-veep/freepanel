<?php

namespace App\Site;

class ReverseProxySite extends Site
{
    private const TYPE = "reverse-proxy";
    protected string $type = self::TYPE;
    private ?string $reverseProxyUrl = null;
    public function setReverseProxyUrl(?string $reverseProxyUrl) : void
    {
        $this->reverseProxyUrl = $reverseProxyUrl;
    }
    public function getReverseProxyUrl() : ?string
    {
        return $this->reverseProxyUrl;
    }
}