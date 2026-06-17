<?php

namespace App\CloudPanel\Do;

use App\CloudPanel\Instance as BaseInstance;
use App\Do\Client as DoClient;
class Instance extends BaseInstance
{
    private ?string $dropletId = null;
    private ?DoClient $doClient = null;
    private ?string $regionName = null;
    private ?string $floatingIp = null;
    public function __construct(DoClient $doClient)
    {
        parent::__construct();
        $this->doClient = $doClient;
    }
    public function getRegion() : string
    {
        if (true === is_null($this->region)) {
            $this->region = $this->doClient->getMetaDataValue("region");
        }
        return $this->region;
    }
    public function setRegionName(string $regionName) : void
    {
        $this->regionName = $regionName;
    }
    public function getRegionName() : string
    {
        if (true === is_null($this->regionName)) {
        $droplet = $this->doClient->getDroplet();
        $this->regionName = null === $droplet ? '' : $droplet->getRegionName();
        }
        return $this->regionName;
    }
    public function setDropletId(string $dropletId) : void
    {
        $this->dropletId = $dropletId;
    }
    public function getDropletId() : ?string
    {
        if (true === is_null($this->dropletId)) {
            $this->dropletId = $this->doClient->getMetaDataValue("id");
        }
        return $this->dropletId;
    }
    public function setFloatingIp(string $floatingIp) : void
    {
        $this->floatingIp = $floatingIp;
    }
    public function getFloatingIp() : string
    {
        if (true === is_null($this->floatingIp)) {
            $this->floatingIp = $this->doClient->getMetaDataValue("floating_ip/ipv4/ip_address");
        }
        return $this->floatingIp;
    }
    public function setIpv4PublicIp(string $ipv4PublicIp) : void
    {
        $this->ipv4PublicIp = $ipv4PublicIp;
    }
    public function getIpv4PublicIp() : ?string
    {
        if (true === is_null($this->ipv4PublicIp)) {
            $this->ipv4PublicIp = $this->doClient->getMetaDataValue("interfaces/public/0/ipv4/address");
        }
        return $this->ipv4PublicIp;
    }
    public function getDoClient() : ?DoClient
    {
        return $this->doClient;
    }
}