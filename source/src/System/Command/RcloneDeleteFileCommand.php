<?php

namespace App\System\Command;

use App\System\Command;

class RcloneDeleteFileCommand extends Command
{
    private ?string $remotePath = null;
    private array $flags = [];

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        $remotePath = (string) $this->remotePath;
        $renderedFlags = $this->getRenderedFlags();
        $this->command = trim(sprintf(
            '/usr/bin/sudo /usr/bin/rclone deletefile remote:%s %s',
            escapeshellarg($remotePath),
            $renderedFlags
        ));
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        return true;
    }

    public function setRemotePath(string $remotePath) : void
    {
        $this->remotePath = $remotePath;
    }

    public function setConfigFile(string $configFile) : void
    {
        $this->addFlag('--config', $configFile);
    }

    public function setGoogleDriveEmail(string $email) : void
    {
        $this->addFlag('--drive-impersonate', $email);
    }

    public function addFlag(string $flag, string $value) : void
    {
        $this->flags[] = ['flag' => $flag, 'value' => $value];
    }

    private function getRenderedFlags() : string
    {
        $parts = [];
        foreach ($this->flags as $flag) {
            $parts[] = sprintf('%s=%s', $flag['flag'], escapeshellarg((string) $flag['value']));
        }
        return implode(' ', $parts);
    }
}
