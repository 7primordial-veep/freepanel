<?php

namespace App\CloudPanel\Hetzner;

use App\CloudPanel\Instance as BaseInstance;
use App\Hetzner\Client as HetznerClient;
class Instance extends BaseInstance
{
    private HetznerClient $hetznerClient;
    private ?string $instanceId = null;
    public function __construct(HetznerClient $hetznerClient)
    {
        parent::__construct();
        $this->hetznerClient = $hetznerClient;
    }
    public function setInstanceId(string $instanceId) : void
    {
        $this->instanceId = $instanceId;
    }
    public function getRegion() : string
    {
        if (true === is_null($this->region)) {
            $this->region = $this->hetznerClient->getMetaDataRegion();
        }
        return $this->region;
    }
    public function getInstanceId() : string
    {
        if (true === is_null($this->instanceId)) {
            $this->instanceId = $this->hetznerClient->getMetaDataInstanceId();
        }
        return $this->instanceId;
    }
    public function getIpv4PublicIp() : ?string
    {
        if (true === is_null($this->ipv4PublicIp)) {
            $this->ipv4PublicIp = $this->hetznerClient->getMetaDataIpv4PublicIp();
        }
        return $this->ipv4PublicIp;
    }
    public function getHetznerClient() : HetznerClient
    {
        return $this->hetznerClient;
    }
}