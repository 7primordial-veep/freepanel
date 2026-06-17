<?php

namespace App\CloudPanel\Vultr;

use App\CloudPanel\Instance as BaseInstance;
use App\Vultr\Client as VultrClient;
class Instance extends BaseInstance
{
    private ?string $instanceId = null;
    private VultrClient $vultrClient;
    public function __construct(VultrClient $vultrClient)
    {
        parent::__construct();
        $this->vultrClient = $vultrClient;
    }
    public function setInstanceId(string $instanceId) : void
    {
        $this->instanceId = $instanceId;
    }
    public function getInstanceId() : string
    {
        if (true === is_null($this->instanceId)) {
            $this->instanceId = $this->vultrClient->getMetaDataInstanceId();
        }
        return $this->instanceId;
    }
    public function getIpv4PublicIp() : ?string
    {
        if (true === is_null($this->ipv4PublicIp)) {
            $this->ipv4PublicIp = $this->vultrClient->getMetaDataIpv4PublicIp();
        }
        return $this->ipv4PublicIp;
    }
    public function getRegion() : string
    {
        if (true === is_null($this->region)) {
            $this->region = $this->vultrClient->getMetaDataRegion();
        }
        return $this->region;
    }
    public function getVultrClient() : VultrClient
    {
        return $this->vultrClient;
    }
}