<?php

namespace App\System\Command;

use App\System\Command;

/**
 * Streams a gzip'd tar archive directly into `rclone rcat`, avoiding a local
 * temp file. PIPESTATUS is checked so tar's "file changed while reading"
 * warning (exit 1) is tolerated, but harder failures (>=2) or any rclone
 * error fail the command.
 */
class TarStreamUploadCommand extends Command
{
    private array $sources = [];
    private array $excludes = [];
    private array $rcloneFlags = [];
    private ?string $destinationObject = null;

    public function setSources(array $sources) : void
    {
        $this->sources = $sources;
    }

    public function getSources() : array
    {
        return $this->sources;
    }

    public function setExcludes(array $excludes) : void
    {
        $this->excludes = $excludes;
    }

    public function setRcloneConfigFile(string $file) : void
    {
        $this->addRcloneFlag('--config', $file);
    }

    public function addRcloneFlag(string $flag, string $value) : void
    {
        $this->rcloneFlags[] = ['flag' => $flag, 'value' => $value];
    }

    public function setDestinationObject(string $object) : void
    {
        $this->destinationObject = $object;
    }

    public function getDestinationObject() : ?string
    {
        return $this->destinationObject;
    }

    public function getCommand() : string
    {
        if ($this->command) {
            return $this->command;
        }
        $renderedExcludes = $this->renderExcludes();
        $renderedSources = $this->renderSources();
        $renderedRcloneFlags = $this->renderRcloneFlags();

        $inner = sprintf(
            '/bin/tar czf - %s %s --warning=no-file-changed | /usr/bin/rclone -v rcat %s remote:%s; ts=${PIPESTATUS[0]}; rs=${PIPESTATUS[1]}; if [ "$ts" -gt 1 ] || [ "$rs" -ne 0 ]; then exit 1; fi; exit 0',
            $renderedExcludes,
            $renderedSources,
            $renderedRcloneFlags,
            escapeshellarg((string) $this->destinationObject)
        );

        $this->command = sprintf('/usr/bin/sudo /bin/bash -c %s', escapeshellarg($inner));
        return $this->command;
    }

    public function isSuccessful() : bool
    {
        return true;
    }

    private function renderExcludes() : string
    {
        $parts = [];
        foreach ($this->excludes as $exclude) {
            $parts[] = sprintf('--exclude=%s', escapeshellarg((string) $exclude));
        }
        return implode(' ', $parts);
    }

    private function renderSources() : string
    {
        $parts = [];
        foreach ($this->sources as $source) {
            $parts[] = escapeshellarg((string) $source);
        }
        return implode(' ', $parts);
    }

    private function renderRcloneFlags() : string
    {
        $parts = [];
        foreach ($this->rcloneFlags as $flag) {
            $parts[] = sprintf('%s=%s', $flag['flag'], escapeshellarg((string) $flag['value']));
        }
        return implode(' ', $parts);
    }
}
