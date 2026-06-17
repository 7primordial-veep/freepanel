<?php

namespace App\CloudPanel;

class Environment
{
    const RELEASE_CHANNEL_STABLE = "stable";
    const RELEASE_CHANNEL_TEST = "test";
    const CLOUD_PROVIDER_AWS = "aws";
    const CLOUD_PROVIDER_DO = "do";
    const CLOUD_PROVIDER_GCE = "gce";
    const CLOUD_PROVIDER_HETZNER = "hetzner";
    const CLOUD_PROVIDER_VULTR = "vultr";
    private ?string $cloudProvider = null;
    public function setCloudProvider(string $cloudProvider) : void
    {
        $this->cloudProvider = $cloudProvider;
    }
    public function getCloudProvider() : ?string
    {
        return $this->cloudProvider;
    }
}