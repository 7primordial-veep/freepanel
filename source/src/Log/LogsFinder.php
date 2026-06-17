<?php

namespace App\Log;

use App\System\CommandExecutor;
use App\System\Command\LsCommand;
class LogsFinder
{
    private string $directory;
    private CommandExecutor $commandExecutor;
    public function __construct(string $directory)
    {
        $this->directory = $directory;
        $this->commandExecutor = new CommandExecutor();
    }
    public function getLogfiles()
    {
        $logfiles = [];
        $lsCommand = new LsCommand();
        $lsCommand->setDirectory($this->directory);
        $this->commandExecutor->execute($lsCommand);
        $output = trim($lsCommand->getOutput());
        if (false === empty($output)) {
            $logfiles = explode(PHP_EOL, $output);
            if (false === empty($logfiles)) {
                $logfiles = array_filter($logfiles, function ($value) {
                    return strpos($value, ".log") !== false;
                });
                $logfiles = array_values($logfiles);
            }
        }
        return $logfiles;
    }
}