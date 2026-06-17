<?php

namespace App\System\Command;

use App\System\Command;

class AppendProjectsLineCommand extends Command
{
    private const ALLOWED_PATHS = ['/etc/projects', '/etc/projid'];

    private ?string $path = null;
    private ?string $line = null;

    public function setPath(string $path) : void
    {
        if (!in_array($path, self::ALLOWED_PATHS, true)) {
            throw new \RuntimeException(sprintf('AppendProjectsLineCommand: path %s not allowed.', $path));
        }
        $this->path = $path;
    }

    public function setLine(string $line) : void
    {
        if (false !== strpos($line, "\n")) {
            throw new \RuntimeException('AppendProjectsLineCommand: line must not contain newlines.');
        }
        $this->line = $line;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (null === $this->path || null === $this->line) {
            throw new \RuntimeException('AppendProjectsLineCommand: path and line must be set.');
        }
        // grep -qxF || echo >> -- idempotent append.
        $this->command = sprintf(
            '/usr/bin/sudo /bin/sh -c %s',
            escapeshellarg(sprintf(
                '/usr/bin/touch %1$s; /bin/grep -qxF -- %2$s %1$s || /bin/echo %2$s >> %1$s',
                escapeshellarg($this->path),
                escapeshellarg($this->line)
            ))
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
