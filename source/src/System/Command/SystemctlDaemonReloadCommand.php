<?php

namespace App\System\Command;

use App\System\Command;

class SystemctlDaemonReloadCommand extends Command
{
    public function __construct()
    {
        $this->command = '/bin/systemctl daemon-reload';
    }

    public function isSuccessful() : bool
    {
        return true;
    }
}
