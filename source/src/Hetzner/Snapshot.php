<?php

namespace App\Hetzner;

class Snapshot
{
    const STATUS_AVAILABLE = "available";
    const STATUS_CREATING = "creating";
    private ?string $id = null;
    private ?\DateTime $createdAt = null;
    private ?string $name = null;
    private ?string $size = "0.00";
    private ?string $status = null;
    private ?string $type = null;
    private bool $isDeleteProtected = false;
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
    public function getSize() : ?string
    {
        return $this->size;
    }
    public function setSize(string $size) : void
    {
        $this->size = $size;
    }
    public function getStatus() : ?string
    {
        return $this->status;
    }
    public function setStatus(string $status) : void
    {
        $this->status = $status;
    }
    public function getName() : ?string
    {
        return $this->name;
    }
    public function setName(string $name) : void
    {
        $this->name = $name;
    }
    public function getType() : ?string
    {
        return $this->type;
    }
    public function setType(string $type) : void
    {
        $this->type = $type;
    }
    public function isDeleteProtected() : bool
    {
        return $this->isDeleteProtected;
    }
    public function setIsDeleteProtected(bool $flag) : void
    {
        $this->isDeleteProtected = $flag;
    }
}