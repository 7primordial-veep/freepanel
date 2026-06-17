<?php

namespace App\Site\Updater;

use App\Site\Updater as SiteUpdater;
use App\System\Command\WriteFileCommand;
class PythonSite extends SiteUpdater
{
    public function pythonSettings() : void
    {
        $this->updateNginxVhost();
        $this->reloadNginxService();
    }
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