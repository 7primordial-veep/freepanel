<?php

namespace App\Vultr;

class Snapshot
{
    const STATUS_COMPLETE = "complete";
    const STATUS_PENDING = "pending";
    private ?string $id = null;
    private ?\DateTime $createdAt = null;
    private ?string $description = null;
    private ?int $size = 0;
    private ?string $compressedSize = null;
    private ?string $status = null;
    public function getId() : ?string
    {
        return $this->id;
    }
    public function setId(string $id) : void
    {
        $this->id = $id;
    }
    public function getCreatedAt() : ?\DateTime
    {
        return $this->createdAt;
    }
    public function setCreatedAt(\DateTime $createdAt) : void
    {
        $this->createdAt = $createdAt;
    }
    public function setDescription(string $description) : void
    {
        $this->description = $description;
    }
    public function getDescription() : ?string
    {
        return $this->description;
    }
    public function getSize() : ?int
    {
        return $this->size;
    }
    public function setSize(int $size) : void
    {
        $this->size = $size;
    }
    public function getCompressedSize() : ?string
    {
        return $this->compressedSize;
    }
    public function setCompressedSize(string $compressedSize) : void
    {
        $this->compressedSize = $compressedSize;
    }
    public function getStatus() : ?string
    {
        return $this->status;
    }
    public function setStatus(string $status) : void
    {
        $this->status = $status;
    }
}