<?php

namespace App\System\Command;

use App\System\Command;

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
        $this->assertSafeName($this->fileName);
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

    private function assertSafeName(string $name) : void
    {
        if ('' === $name) {
            throw new \RuntimeException('RemoveSliceFileCommand: empty file name.');
        }
        if ('/' === $name[0]) {
            throw new \RuntimeException('RemoveSliceFileCommand: absolute paths rejected.');
        }
        if (false !== strpos($name, '..')) {
            throw new \RuntimeException('RemoveSliceFileCommand: ".." not allowed in file name.');
        }
        if (false !== strpos($name, '/') && 1 !== substr_count($name, '/')) {
            throw new \RuntimeException('RemoveSliceFileCommand: only single-level drop-in subdir allowed.');
        }
        if (!preg_match('#^[A-Za-z0-9._@-]+(?:/[A-Za-z0-9._@-]+)?$#', $name)) {
            throw new \RuntimeException('RemoveSliceFileCommand: invalid characters in file name.');
        }
    }
}
