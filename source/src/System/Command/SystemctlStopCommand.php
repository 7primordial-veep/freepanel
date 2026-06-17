<?php

namespace App\System\Command;

use App\System\Command;

class SystemctlStopCommand extends Command
{
    private ?string $unit = null;

    public function getCommand() : string
    {
        if (!$this->command) {
            $unit = $this->getUnit();
            $this->command = sprintf("/usr/bin/sudo /bin/systemctl stop %s", escapeshellarg($unit));
        }
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = trim((string) $this->getOutput());
        if ('' === $output) {
            return true;
        }
        if (false !== stripos($output, 'failed') || false !== stripos($output, 'error')) {
            return false;
        }
        return true;
    }

    public function setUnit(string $unit) : void
    {
        $this->unit = $unit;
    }

    public function getUnit() : ?string
    {
        return $this->unit;
    }
}
