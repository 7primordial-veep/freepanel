<?php

namespace App\System\Command;

use App\System\Command;

class WritePhpFpmPoolDirectiveCommand extends Command
{
    private ?string $path = null;
    private ?string $directive = null;
    private ?string $value = null;

    public function setPath(string $path) : void
    {
        $this->path = $path;
    }

    public function setDirective(string $directive) : void
    {
        $this->directive = $directive;
    }

    public function setValue(string $value) : void
    {
        $this->value = $value;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }

        if (null === $this->path || null === $this->directive || null === $this->value) {
            throw new \RuntimeException('path, directive, and value are required');
        }

        if (!preg_match('/^\/etc\/php\/\d+\.\d+\/fpm\/pool\.d\/[a-z_][a-z0-9_-]{0,31}\.conf$/', $this->path)) {
            throw new \RuntimeException('invalid pool path');
        }

        if (!preg_match('/^[a-z][a-z._]+$/', $this->directive)) {
            throw new \RuntimeException('invalid directive name');
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $this->value)) {
            throw new \RuntimeException('invalid directive value');
        }

        $sedExpr = sprintf(
            's|^[[:space:]]*%s[[:space:]]*=.*|%s = %s|',
            preg_quote($this->directive, '|'),
            $this->directive,
            $this->value
        );

        $this->command = sprintf(
            '/usr/bin/sudo /bin/sed -i -E %s %s 2>&1',
            escapeshellarg($sedExpr),
            escapeshellarg($this->path)
        );

        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = (string) $this->getOutput();
        if ('' === trim($output)) {
            return true;
        }
        $lower = strtolower($output);
        if (false !== strpos($lower, 'error')
            || false !== strpos($lower, 'denied')
            || false !== strpos($lower, 'cannot')) {
            return false;
        }
        return true;
    }
}
