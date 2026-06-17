<?php

namespace App\Log;

use App\System\CommandExecutor;
use App\System\Command\TailCommand;
class LogfileReader
{
    private string $logfile;
    private CommandExecutor $commandExecutor;
    public function __construct(string $logfile)
    {
        $this->logfile = $logfile;
        $this->commandExecutor = new CommandExecutor();
    }
    public function getLines(int $numberOfLines) : ?string
    {
        $tailFileCommand = new TailCommand();
        $tailFileCommand->setFile($this->logfile);
        $tailFileCommand->setNumberOfLines($numberOfLines);
        $this->commandExecutor->execute($tailFileCommand);
        $lines = trim($tailFileCommand->getOutput());
        return $lines;
    }
}