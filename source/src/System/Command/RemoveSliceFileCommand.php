<?php

namespace App\System\Command;

use App\System\Command;
use App\System\Command\Util\SystemdUnitName;

class RemoveSliceFileCommand extends Command
{
    private ?string $fileName = null;

    public function setFileName(string $fileName) : void
    {
        $this->fileName = $fileName;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->fileName) {
            throw new \RuntimeException('RemoveSliceFileCommand: fileName must be set.');
        }
        SystemdUnitName::assertSafe($this->fileName);
        $path = '/etc/systemd/system/' . $this->fileName;
        $this->command = sprintf('/usr/bin/sudo /bin/rm -f %s', escapeshellarg($path));
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = trim((string) $this->getOutput());
        if ('' === $output) {
            return true;
        }
        if (false !== stripos($output, 'error') || false !== stripos($output, 'denied')) {
            return false;
        }
        return true;
    }
}
