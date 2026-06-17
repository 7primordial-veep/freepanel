<?php

namespace App\CloudPanel\Gce;

use App\CloudPanel\Instance as BaseInstance;
use App\Gce\Client as GceClient;
class Instance extends BaseInstance
{
    private ?GceClient $gceClient = null;
    private ?string $instanceId = null;
    private ?string $instanceName = null;
    private ?string $machineType = null;
    private ?string $projectId = null;
    private ?string $zone = null;
    public function __construct(GceClient $gceClient)
    {
        parent::__construct();
        $this->gceClient = $gceClient;
    }
    public function setGceClient(GceClient $gceClient) : void
    {
        $this->gceClient = $gceClient;
    }
    public function getGceClient() : ?GceClient
    {
        return $this->gceClient;
    }
    public function setInstanceId(string $instanceId) : void
    {
        $this->instanceId = $instanceId;
    }
    public function getInstanceId() : string
    {
        if (true === is_null($this->instanceId)) {
            $this->instanceId = $this->gceClient->getMetaDataInstanceId();
        }
        return $this->instanceId;
    }
    public function setInstanceName(string $instanceName) : void
    {
        $this->instanceName = $instanceName;
    }
    public function getInstanceName() : string
    {
        if (true === is_null($this->instanceName)) {
            $this->instanceName = $this->gceClient->getMetaDataInstanceName();
        }
        return $this->instanceName;
    }
    public function setIpv4PublicIp(string $ipv4PublicIp) : void
    {
        $this->ipv4PublicIp = $ipv4PublicIp;
    }
    public function getIpv4PublicIp() : ?string
    {
        if (true === is_null($this->ipv4PublicIp)) {
            $this->ipv4PublicIp = $this->gceClient->getMetaDataIpv4PublicIp();
        }
        return $this->ipv4PublicIp;
    }
    public function setMachineType(string $machineType) : void
    {
        $this->machineType = $machineType;
    }
    public function getMachineType() : string
    {
        if (true === is_null($this->machineType)) {
            $this->machineType = $this->gceClient->getMetaDataMachineType();
        }
        return $this->machineType;
    }
    public function setProjectId(string $projectId) : void
    {
        $this->projectId = $projectId;
    }
    public function getProjectId() : string
    {
        if (true === is_null($this->projectId)) {
            $this->projectId = $this->gceClient->getMetaDataProjectId();
        }
        return $this->projectId;
    }
    public function setZone(string $zone) : void
    {
        $this->zone = $zone;
    }
    public function getZone() : string
    {
        if (true === is_null($this->zone)) {
            $this->zone = $this->gceClient->getMetaDataZone();
        }
        return $this->zone;
    }
}