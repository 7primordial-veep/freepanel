<?php

namespace App\System\Command;

use App\System\Command;
use App\System\Command\Util\SystemdUnitName;

class WriteSliceFileCommand extends Command
{
    private ?string $fileName = null;
    private ?string $content = null;

    public function setFileName(string $fileName) : void
    {
        $this->fileName = $fileName;
    }

    public function setContent(string $content) : void
    {
        $this->content = $content;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->fileName || null === $this->content) {
            throw new \RuntimeException('WriteSliceFileCommand: fileName and content must be set.');
        }
        SystemdUnitName::assertSafe($this->fileName);
        $path = '/etc/systemd/system/' . $this->fileName;
        $dir = dirname($path);
        $this->command = sprintf(
            '/usr/bin/sudo /bin/mkdir -p %s && printf %%s %s | /usr/bin/sudo /usr/bin/tee %s > /dev/null && /usr/bin/sudo /bin/chmod 0644 %s',
            escapeshellarg($dir),
            escapeshellarg($this->content),
            escapeshellarg($path),
            escapeshellarg($path)
        );
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = trim((string) $this->getOutput());
        if ('' === $output) {
            return true;
        }
        if (false !== stripos($output, 'error') || false !== stripos($output, 'denied') || false !== stripos($output, 'no such')) {
            return false;
        }
        return true;
    }
}
