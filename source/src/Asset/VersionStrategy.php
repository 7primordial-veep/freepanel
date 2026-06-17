<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
class VersionStrategy implements VersionStrategyInterface
{
    private ?string $format = '';
    public function __construct($format = null)
    {
        $this->format = $format ?: "%s?%s";
    }
    public function getVersion($path) : string
    {
        $environment = $_ENV["APP_ENV"];
        if (true === in_array($environment, ["dev", "test"])) {
            $version = time();
        } else {
            $version = $_ENV["APP_VERSION"];
        }
        return $version;
    }
    public function applyVersion($path) : string
    {
        $version = $this->getVersion($path);
        if ('' === $version) {
            return $path;
        }
        $versionized = sprintf($this->format, ltrim($path, "/"), $version);
        if ($path && "/" === $path[0]) {
            return "/" . $versionized;
        }
        return $versionized;
    }
}