<?php

namespace App\System\Command;

use App\System\Command;
class ProcessorCoresCommand extends Command
{
    public function getCommand() : string
    {
        if (!$this->command) {
            $this->command = "/usr/bin/sudo /bin/cat /proc/cpuinfo | /bin/grep processor | wc -l";
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = false === empty($output);
        return $isSuccessful;
    }
    public function getNumberOfProcessorCores() : int
    {
        $numberOfProcessorCores = (int) trim($this->getOutput());
        return $numberOfProcessorCores;
    }
}