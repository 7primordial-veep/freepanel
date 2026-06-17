<?php

namespace App\System\Command;

use App\System\Command;
class RcloneCopyCommand extends Command
{
    private ?string $source = null;
    private ?string $destination = null;
    private array $flags = [];
    public function getCommand() : string
    {
        if (!$this->command) {
            $source = $this->getSource();
            $destination = $this->getDestination();
            $renderedFlags = $this->getRenderedFlags();
            $this->command = trim(sprintf("/usr/bin/sudo /usr/bin/rclone -v copy %s remote:%s %s", escapeshellarg($source), escapeshellarg($destination), $renderedFlags));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setSource(string $source) : void
    {
        $this->source = $source;
    }
    public function getSource() : ?string
    {
        return $this->source;
    }
    public function setDestination(?string $destination) : void
    {
        $this->destination = $destination;
    }
    public function getDestination() : ?string
    {
        return $this->destination;
    }
    public function setConfigFile(string $configFile) : void
    {
        $this->addFlag("--config", $configFile);
    }
    public function addFlag(string $flag, string $value)
    {
        $this->flags[] = ["flag" => $flag, "value" => $value];
    }
    public function getFlags() : array
    {
        return $this->flags;
    }
    private function getRenderedFlags() : string
    {
        $renderedFlags = [];
        $flags = $this->getFlags();
        foreach ($flags as $flag) {
            $renderedFlags[] = sprintf("%s=%s", $flag["flag"], escapeshellarg($flag["value"]));
        }
        $renderedFlags = implode(" ", $renderedFlags);
        return $renderedFlags;
    }
}