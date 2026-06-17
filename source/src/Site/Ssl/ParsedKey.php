<?php

namespace App\Site\Ssl;

class ParsedKey
{
    private ?Key $source;
    private ?string $key = null;
    private ?string $bits = null;
    private ?string $type = null;
    private array $details = [];
    public function __construct(Key $source, $key, $bits, $type, array $details = [])
    {
        $this->source = $source;
        $this->key = $key;
        $this->bits = $bits;
        $this->type = $type;
        $this->details = $details;
    }
    public function getSource() : Key
    {
        return $this->source;
    }
    public function getKey() : ?string
    {
        return $this->key;
    }
    public function getBits() : ?string
    {
        return $this->bits;
    }
    public function getType() : ?string
    {
        return $this->type;
    }
    public function getDetails() : ?string
    {
        return $this->details;
    }
    public function hasDetail($name) : bool
    {
        return isset($this->details[$name]);
    }
    public function getDetail($name) : ?string
    {
        return $this->details[$name];
    }
}