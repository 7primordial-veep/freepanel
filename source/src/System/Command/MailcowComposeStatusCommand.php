<?php

declare(strict_types=1);

namespace App\System\Command;

use App\System\Command;

/**
 * Counts the number of mailcow containers currently in the "running" state.
 *
 * Output is a single integer (the line count from `wc -l`). Callers compare
 * that count against an expected minimum to decide if the stack is healthy.
 */
class MailcowComposeStatusCommand extends Command
{
    public function getCommand(): string
    {
        if (!$this->command) {
            $this->command = '/usr/bin/docker compose -f /opt/mailcow-dockerized/docker-compose.yml '
                . 'ps --status running --quiet 2>/dev/null | /usr/bin/wc -l';
        }

        return $this->command;
    }

    public function isSuccessful(): bool
    {
        return true;
    }
}
