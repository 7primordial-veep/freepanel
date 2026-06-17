<?php

namespace App\System\Command;

use App\System\Command;

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
        $this->assertSafeName($this->fileName);
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

    private function assertSafeName(string $name) : void
    {
        if ('' === $name) {
            throw new \RuntimeException('WriteSliceFileCommand: empty file name.');
        }
        if ('/' === $name[0]) {
            throw new \RuntimeException('WriteSliceFileCommand: absolute paths rejected.');
        }
        if (false !== strpos($name, '..')) {
            throw new \RuntimeException('WriteSliceFileCommand: ".." not allowed in file name.');
        }
        // Allow at most one level of "<unit>.<type>.d/<file>.conf" for drop-ins.
        if (false !== strpos($name, '/') && 1 !== substr_count($name, '/')) {
            throw new \RuntimeException('WriteSliceFileCommand: only single-level drop-in subdir allowed.');
        }
        if (!preg_match('#^[A-Za-z0-9._@-]+(?:/[A-Za-z0-9._@-]+)?$#', $name)) {
            throw new \RuntimeException('WriteSliceFileCommand: invalid characters in file name.');
        }
    }
}
