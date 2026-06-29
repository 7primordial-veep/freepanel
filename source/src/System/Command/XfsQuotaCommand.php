<?php

namespace App\System\Command;

use App\System\Command;

class XfsQuotaCommand extends Command
{
    private array $expressions = [];
    private ?string $mountPoint = null;

    public function setExpressions(array $expressions) : void
    {
        $this->expressions = $expressions;
    }

    public function setMountPoint(string $mountPoint) : void
    {
        $this->mountPoint = $mountPoint;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        if (empty($this->expressions) || null === $this->mountPoint) {
            throw new \RuntimeException('XfsQuotaCommand: expressions and mountPoint must be set.');
        }
        $parts = ['/usr/bin/sudo', '/usr/sbin/xfs_quota', '-x'];
        foreach ($this->expressions as $expr) {
            $parts[] = '-c';
            $parts[] = escapeshellarg($expr);
        }
        $parts[] = escapeshellarg($this->mountPoint);
        $this->command = implode(' ', $parts) . ' 2>&1';
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = strtolower((string) $this->getOutput());
        if ('' === trim($output)) {
            return true;
        }
        foreach (['error', 'failed', 'not supported', 'no such', 'not a mount point', 'permission denied'] as $needle) {
            if (false !== strpos($output, $needle)) {
                return false;
            }
        }
        return true;
    }
}
