<?php

namespace App\System\Command;

use App\System\Command;

class SystemdRunCommand extends Command
{
    private ?string $slice = null;
    private ?string $unit = null;
    private ?string $wrappedCommand = null;
    private bool $remainAfterExit = false;
    private array $properties = [];

    public function getCommand() : string
    {
        if (!$this->command) {
            $slice = $this->getSlice();
            $unit = $this->getUnit();
            $wrapped = $this->getWrappedCommand();
            if (true === empty($wrapped)) {
                throw new \RuntimeException('SystemdRunCommand requires a wrapped command.');
            }
            $parts = ['/usr/bin/sudo', '/bin/systemd-run'];
            if (false === empty($slice)) { $parts[] = sprintf('--slice=%s', escapeshellarg($slice)); }
            if (false === empty($unit)) { $parts[] = sprintf('--unit=%s', escapeshellarg($unit)); }
            foreach ($this->properties as $k => $v) { $parts[] = sprintf('--property=%s', escapeshellarg(sprintf('%s=%s', $k, (string)$v))); }
            if (true === $this->remainAfterExit) { $parts[] = '-r'; }
            $parts[] = '--';
            $parts[] = $wrapped;
            $this->command = implode(' ', $parts);
        }
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        $output = trim((string) $this->getOutput());
        if ('' === $output) { return true; }
        if (false !== stripos($output, 'failed') || false !== stripos($output, 'error')) { return false; }
        return true;
    }

    public function setSlice(string $slice) : void { $this->slice = $slice; }
    public function getSlice() : ?string { return $this->slice; }
    public function setUnit(string $unit) : void { $this->unit = $unit; }
    public function getUnit() : ?string { return $this->unit; }
    public function setWrappedCommand(string $command) : void { $this->wrappedCommand = $command; }
    public function getWrappedCommand() : ?string { return $this->wrappedCommand; }
    public function setRemainAfterExit(bool $flag) : void { $this->remainAfterExit = $flag; }
    public function setProperty(string $key, string $value) : void { $this->properties[$key] = $value; }
}
