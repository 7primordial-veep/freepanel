<?php

namespace App\Backup;

use App\System\CommandExecutor;
use App\System\Command\TarCreateCommand;
class TarCreator
{
    private CommandExecutor $commandExecutor;
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function create(array $sources, string $destinationFile, array $excludes = [])
    {
        $tarCreateCommand = new TarCreateCommand();
        if (false === empty($excludes)) {
            $tarCreateCommand->setExcludes($excludes);
        }
        $tarCreateCommand->setSources($sources);
        $tarCreateCommand->setDestinationFile($destinationFile);
        $this->commandExecutor->execute($tarCreateCommand, 21600);
    }
}