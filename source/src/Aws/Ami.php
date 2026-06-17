<?php

namespace App\Aws;

class Ami
{
    const STATE_AVAILABLE = "available";
    const STATE_PENDING = "pending";
    const STATE_FAILED = "failed";
    const TYPE_AUTOMATED = "automated";
    const TYPE_MANUAL = "manual";
    private ?string $amiId = null;
    private ?\DateTime $createdAt = null;
    private ?string $state = null;
    private ?string $name = null;
    private ?string $description = null;
    private array $blockDeviceMappings = [];
    private array $tags = [];
    public function setAmiId(string $amiId) : void
    {
        $this->amiId = $amiId;
    }
    public function getAmiId() : ?string
    {
        return $this->amiId;
    }
    public function setCreatedAt(\DateTime $createdAt) : void
    {
        $this->createdAt = $createdAt;
    }
    public function getCreatedAt() : ?\DateTime
    {
        return $this->createdAt;
    }
    public function setName(string $name) : void
    {
        $this->name = $name;
    }
    public function getName() : ?string
    {
        return $this->name;
    }
    public function setDescription(string $description) : void
    {
        $this->description = $description;
    }
    public function getDescription() : ?string
    {
        return $this->description;
    }
    public function setState(string $state) : void
    {
        $this->state = $state;
    }
    public function getState() : ?string
    {
        return $this->state;
    }
    public function setBlockDeviceMappings(array $blockDeviceMappings) : void
    {
        $this->blockDeviceMappings = $blockDeviceMappings;
    }
    public function getBlockDeviceMappings() : array
    {
        return $this->blockDeviceMappings;
    }
    public function getType() : string
    {
        $type = $this->getTagValue("Type");
        return $type;
    }
    public function setTags(array $tags) : void
    {
        $this->tags = $tags;
    }
    public function getTags() : array
    {
        return $this->tags;
    }
    public function getTagValue($key) : string
    {
        $tags = $this->getTags();
        foreach ($tags as $tag) {
            $tagKey = $tag["Key"] ?? null;
            $tagValue = $tag["Value"] ?? null;
            if (!($tagKey == $key)) {
                continue;
            }
            return $tagValue;
        }
        return '';
    }
}