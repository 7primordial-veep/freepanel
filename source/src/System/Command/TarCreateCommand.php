<?php

namespace App\System\Command;

use App\System\Command;
class TarCreateCommand extends Command
{
    private array $sources = [];
    private ?string $destinationFile = null;
    private array $flags = [];
    public function getCommand() : string
    {
        if (!$this->command) {
            $sources = $this->getSources();
            $destinationFile = $this->getDestinationFile();
            $renderedFlags = $this->getRenderedFlags();
            $this->command = sprintf("/usr/bin/sudo /bin/tar cfv %s %s %s --warning=no-file-changed", escapeshellarg($destinationFile), $renderedFlags, implode(" ", $sources));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setSources(array $sources) : void
    {
        $this->sources = $sources;
    }
    public function getSources() : array
    {
        return $this->sources;
    }
    public function setDestinationFile(string $destinationFile) : void
    {
        $this->destinationFile = $destinationFile;
    }
    public function getDestinationFile() : ?string
    {
        return $this->destinationFile;
    }
    public function setExcludes(array $excludes) : void
    {
        foreach ($excludes as $path) {
            $this->addFlag("--exclude", $path);
        }
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