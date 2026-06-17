<?php

namespace App\System\Command;

use App\System\Command;
class MemoryInformationCommand extends Command
{
    public function getCommand() : string
    {
        if (!$this->command) {
            $this->command = "/usr/bin/sudo /bin/cat /proc/meminfo";
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        $output = $this->getOutput();
        $isSuccessful = false === empty($output);
        return $isSuccessful;
    }
    public function getTotalMemoryInBytes() : int
    {
        $totalMemoryInBytes = 0;
        $memoryInformationOutput = trim($this->getOutput());
        if (false === empty($memoryInformationOutput)) {
            $memoryInformationExploded = explode(PHP_EOL, $memoryInformationOutput);
            if (true === is_array($memoryInformationExploded)) {
                foreach ($memoryInformationExploded as $line) {
                    if (!(strpos($line, "MemTotal") !== false)) {
                        continue;
                    }
                    $totalMemory = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                    if (!($totalMemory > 0)) {
                        break;
                    }
                    $totalMemoryInBytes = $totalMemory * 1024;
                }
            }
        }
        return $totalMemoryInBytes;
    }
}