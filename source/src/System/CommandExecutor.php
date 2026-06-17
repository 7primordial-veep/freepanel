<?php

namespace App\System;

class CommandExecutor
{
    public function execute(Command $command, $timeout = 30) : void
    {
        try {
            $runInBackground = $command->runInBackground();
            if (true === $runInBackground) {
                // Detach completely — Symfony Process::__destruct() would otherwise SIGKILL
                // the started process when this executor goes out of scope. The command builder
                // is responsible for setsid/nohup + & to background within the shell.
                shell_exec($command->getCommand());
                return;
            }
            $process = Process::fromShellCommandline($command->getCommand(), "/tmp/");
            $process->setCommand($command);
            $process->setTimeout($timeout);
            $process->run();
            if (false === $process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Command "%s" failed: %s', $command->getCommand(), $e->getMessage()), 0, $e);
        }
    }
}