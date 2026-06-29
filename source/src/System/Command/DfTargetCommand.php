<?php

namespace App\System\Command;

use App\System\Command;

/**
 * Resolves the mount point of a given path by invoking `df --output=target`.
 *
 * Output format is two lines: a header ("Mounted on") and the mount point.
 * getMountPoint() returns the second line trimmed.
 */
class DfTargetCommand extends Command
{
    private ?string $path = null;

    public function setPath(string $path) : void
    {
        $this->path = $path;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->path) {
            throw new \RuntimeException('DfTargetCommand: path must be set.');
        }
        $this->command = sprintf('/bin/df --output=target %s 2>/dev/null', escapeshellarg($this->path));
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        return true;
    }

    public function getMountPoint() : ?string
    {
        $output = (string) $this->getOutput();
        if ('' === $output) {
            return null;
        }
        $lines = preg_split('/\r?\n/', $output) ?: [];
        // Drop empty lines so the second non-empty line is the actual mount.
        $lines = array_values(array_filter(array_map('trim', $lines), static function (string $line) : bool {
            return '' !== $line;
        }));
        if (count($lines) < 2) {
            return null;
        }
        $mount = $lines[1];
        if ('' === $mount || '/' !== $mount[0]) {
            return null;
        }
        return $mount;
    }
}
