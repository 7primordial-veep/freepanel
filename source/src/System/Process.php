<?php

namespace App\System;

use Symfony\Component\Process\Process as BaseProcess;
use App\System\Command\TarCreateCommand;
class Process extends BaseProcess
{
    private ?Command $command = null;
    public function isSuccessful() : bool
    {
        if (true === is_null($this->command)) {
            return parent::isSuccessful();
        }
        if ($this->command instanceof TarCreateCommand) {
            $exitCode = $this->getExitCode();
            $isSuccessful = true === in_array($exitCode, [0, 1]);
        } else {
            $isSuccessful = parent::isSuccessful();
        }
        $output = $this->getErrorOutput() ?: $this->getOutput();
        $output = trim($output);
        $this->command->setOutput($output);
        if (false === $this->command->isSuccessful()) {
            $isSuccessful = false;
            $this->addErrorOutput($output);
        }
        return $isSuccessful;
    }
    public function setCommand(Command $command) : void
    {
        $this->command = $command;
    }
    public function getCommand() : ?Command
    {
        return $this->command;
    }
}