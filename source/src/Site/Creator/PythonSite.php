<?php

namespace App\Site\Creator;

use App\Site\Creator as SiteCreator;
use App\System\Command\WriteFileCommand;
class PythonSite extends SiteCreator
{
    public function writePythonVersionFile() : void
    {
        $siteUser = $this->site->getUser();
        $pythonSettings = $this->site->getPythonSettings();
        $pythonVersion = $pythonSettings->getPythonVersion();
        $pythonVersionFile = sprintf("/home/%s/.python_version", $siteUser);
        $pythonVersionFileContent = sprintf("alias python='/usr/bin/python%s'", $pythonVersion);
        $writePythonVersionFileCommand = new WriteFileCommand();
        $writePythonVersionFileCommand->setFile($pythonVersionFile);
        $writePythonVersionFileCommand->setContent($pythonVersionFileContent);
        $this->commandExecutor->execute($writePythonVersionFileCommand);
    }
}