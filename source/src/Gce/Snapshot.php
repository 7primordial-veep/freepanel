<?php

namespace App\Gce;

class Snapshot
{
    const STATUS_CREATING = "CREATING";
    const STATUS_DELETING = "DELETING";
    const STATUS_FAILED = "FAILED";
    const STATUS_READY = "READY";
    const TYPE_AUTOMATED = "automated";
    const TYPE_MANUAL = "manual";
    private ?string $id = null;
    private ?string $name = null;
    private array $labels = [];
    private ?float $diskSizeGb = 0.0;
    private ?string $disk = null;
    private ?\DateTime $createdAt = null;
    private ?string $status = null;
    private ?string $type = null;
    public function getId() : ?string
    {
        return $this->id;
    }
    public function setId(string $id) : void
    {
        $this->id = $id;
    }
    public function getName() : ?string
    {
        return $this->name;
    }
    public function setName(string $name) : void
    {
        $this->name = $name;
    }
    public function getLabels() : array
    {
        return $this->labels;
    }
    public function setLabels(array $labels) : void
    {
        $this->labels = $labels;
    }
    public function getDiskSizeGb() : ?float
    {
        return $this->diskSizeGb;
    }
    public function setDiskSizeGb(float $diskSizeGb) : void
    {
        $this->diskSizeGb = $diskSizeGb;
    }
    public function getDisk() : ?string
    {
        return $this->disk;
    }
    public function setDisk(string $disk) : void
    {
        $this->disk = $disk;
    }
    public function getCreatedAt() : ?\DateTime
    {
        return $this->createdAt;
    }
    public function setCreatedAt(\DateTime $createdAt) : void
    {
        $this->createdAt = $createdAt;
    }
    public function getStatus() : ?string
    {
        return $this->status;
    }
    public function setStatus(string $status) : void
    {
        $this->status = $status;
    }
    public function getType() : ?string
    {
        return $this->type;
    }
    public function setType(string $type) : void
    {
        $this->type = $type;
    }
}