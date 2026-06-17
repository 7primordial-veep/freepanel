<?php

namespace App\System\Command;

use App\System\Command;
class NvmInstallNodejsCommand extends Command
{
    private ?string $nodejsVersion;
    public function getCommand() : string
    {
        if (!$this->command) {
            $nodeJsVersion = $this->getNodejsVersion();
            $user = $this->getRunAsUser();
            $userDirectory = sprintf("/home/%s/", $user);
            $this->command = sprintf("/usr/bin/sudo -u %s /bin/bash -c \". /home/%s/.nvm/nvm.sh && cd %s && nvm install %s && nvm alias default %s\"", escapeshellarg($user), escapeshellarg($user), escapeshellarg($userDirectory), escapeshellarg($nodeJsVersion), escapeshellarg($nodeJsVersion));
        }
        return $this->command;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setNodejsVersion(string $nodejsVersion) : void
    {
        $this->nodejsVersion = $nodejsVersion;
    }
    public function getNodejsVersion() : ?string
    {
        return $this->nodejsVersion;
    }
}