<?php

namespace App\System\Command;

use App\System\Command;
class CreateSymlinkCommand extends Command
{
    private ?string $source = null;
    private ?string $destination = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $source = $this->getSource();
            $destination = $this->getDestination();
            $runAsUser = $this->getRunAsUser();
            if (true === is_null($runAsUser)) {
                $this->command = sprintf("/bin/bash -c \"/bin/ln -sf %s %s\"", $source, escapeshellarg($source), escapeshellarg($destination));
            } else {
                $this->command = sprintf("/usr/bin/sudo -u %s /bin/bash -c \"/bin/ln -sf %s %s\"", escapeshellarg($runAsUser), escapeshellarg($source), escapeshellarg($destination));
            }
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
    public function getSource() : ?string
    {
        return $this->source;
    }
    public function setSource(?string $source) : void
    {
        $this->source = $source;
    }
    public function getDestination() : ?string
    {
        return $this->destination;
    }
    public function setDestination(?string $destination) : void
    {
        $this->destination = $destination;
    }
}