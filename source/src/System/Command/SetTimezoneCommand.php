<?php

namespace App\System\Command;

use App\System\Command;
class SetTimezoneCommand extends Command
{
    private ?string $timezone = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $timezone = $this->getTimezone();
            $this->command = sprintf("/usr/bin/sudo /usr/bin/timedatectl set-timezone %s", escapeshellarg($timezone));
        }
        return $this->command;
    }
    public function setTimezone(string $timezone) : void
    {
        $this->timezone = $timezone;
    }
    public function getTimezone() : ?string
    {
        return $this->timezone;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = empty($output);
        return $isSuccessful;
    }
}