<?php

namespace App\System\Command;

use App\System\Command;

class SystemctlSetPropertyCommand extends Command
{
    private ?string $unit = null;
    private array $properties = [];

    public function getCommand() : string
    {
        if (!$this->command) {
            $unit = $this->getUnit();
            $properties = $this->getProperties();
            if (true === empty($properties)) {
                throw new \RuntimeException('SystemctlSetPropertyCommand requires at least one property.');
            }
            $args = [];
            foreach ($properties as $key => $value) {
                $args[] = escapeshellarg(sprintf('%s=%s', $key, (string) $value));
            }
            $this->command = sprintf(
                '/usr/bin/sudo /bin/systemctl set-property %s %s',
                escapeshellarg($unit),
                implode(' ', $args)
            );
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

    public function setUnit(string $unit) : void { $this->unit = $unit; }
    public function getUnit() : ?string { return $this->unit; }
    public function setProperties(array $properties) : void { $this->properties = $properties; }
    public function getProperties() : array { return $this->properties; }
}
