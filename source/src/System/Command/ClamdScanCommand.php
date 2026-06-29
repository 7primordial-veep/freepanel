<?php

namespace App\System\Command;

use App\System\Command;

class ClamdScanCommand extends Command
{
    private ?string $path = null;
    private ?string $logFile = null;

    public function setPath(string $path): void { $this->path = $path; }

    public function setLogFile(string $logFile): void { $this->logFile = $logFile; }

    public function getCommand(): string
    {
        if (!$this->command) {
            $path = escapeshellarg((string) $this->path);
            $log = escapeshellarg((string) $this->logFile);
            // clamdscan via the running clamav-daemon. --infected prints only infected files.
            // --fdpass lets clamd read files it cannot open directly (different uid). sudo so root-owned site files are scannable.
            // setsid detaches from the PHP request's process group so the scan survives the response.
            // Symfony Process::start() doesn't itself detach — without setsid the scan dies when PHP-FPM cleans up.
            $this->command = sprintf(
                '/usr/bin/setsid /bin/bash -c %s < /dev/null > /dev/null 2>&1 &',
                escapeshellarg(sprintf(
                    '/usr/bin/sudo /usr/bin/clamdscan --infected --fdpass --no-summary %s > %s 2>&1; echo "__EXIT__$?" >> %s',
                    $path, $log, $log
                ))
            );
        }
        return $this->command;
    }

    public function isSuccessful(): bool
    {
        // clamdscan exit codes: 0 clean, 1 infected, 2 error. We run in background and parse the log file later, so always report true here.
        return true;
    }
}
