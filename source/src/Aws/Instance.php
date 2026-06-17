<?php

namespace App\Aws;

use App\Aws\Regions;
class Instance
{
    const STATE_RUNNING = "running";
    const STATE_STOPPED = "stopped";
    const STATE_PENDING = "pending";
    private ?string $instanceId = null;
    private ?string $instanceType = null;
    private ?string $region = null;
    private ?string $publicIpAddress = null;
    private ?string $privateIpAddress = null;
    private array $securityGroups = [];
    private ?string $state = null;
    private array $tags = [];
    public function setInstanceId(string $instanceId) : void
    {
        $this->instanceId = $instanceId;
    }
    public function getInstanceId() : ?string
    {
        return $this->instanceId;
    }
    public function setInstanceType(string $instanceType) : void
    {
        $this->instanceType = $instanceType;
    }
    public function getInstanceType() : ?string
    {
        return $this->instanceType;
    }
    public function setRegion(string $region)
    {
        $this->region = $region;
    }
    public function getRegion() : ?string
    {
        return $this->region;
    }
    public function setPublicIpAddress(string $publicIpAddress) : void
    {
        $this->publicIpAddress = $publicIpAddress;
    }
    public function getPublicIpAddress() : ?string
    {
        return $this->publicIpAddress;
    }
    public function setPrivateIpAddress(string $privateIpAddress) : void
    {
        $this->privateIpAddress = $privateIpAddress;
    }
    public function getPrivateIpAddress() : ?string
    {
        return $this->privateIpAddress;
    }
    public function setSecurityGroups(array $securityGroups) : void
    {
        $this->securityGroups = $securityGroups;
    }
    public function getSecurityGroups() : array
    {
    return $this->securityGroups;
    }
    public function setState(string $state) : void
    {
        $this->state = $state;
    }
    public function getState() : ?string
    {
        return $this->state;
    }
    public function setTags(array $tags) : void
    {
        $this->tags = $tags;
    }
    public function getTags() : array
    {
        return $this->tags;
    }
    public function getInstanceName() : ?string
    {
        $instanceName = $this->getTagValue("Name");
        return $instanceName;
    }
    private function getTagValue(string $key) : ?string
    {
        $value = '';
        $tags = $this->getTags();
        if (count($tags)) {
            foreach ($tags as $tag) {
                if (!(true === isset($tag["Key"]) && $tag["Key"] == $key && true === isset($tag["Value"]))) {
                    continue;
                }
                $value = $tag["Value"];
                break;
            }
        }
        return $value;
    }
    public function getRegionName() : ?string
    {
        $region = $this->getRegion();
        $regionName = Regions::getRegionName($region);
        return $regionName;
    }
}