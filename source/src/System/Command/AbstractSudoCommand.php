<?php

namespace App\System\Command;

use App\System\Command;

/**
 * Thin base for the small family of `sudo -n -u <user> …` commands.
 *
 * Centralises:
 *  - the target-user slot + setter
 *  - validation against the POSIX login-name regex used elsewhere in the app
 *  - construction of the `/usr/bin/sudo -n -u <escaped-user>` prefix
 *  - a default {@see isSuccessful()} that flags the common stderr keywords
 *    these commands emit when something goes wrong.
 *
 * Subclasses only need to implement {@see getCommand()} by concatenating
 * {@see sudoPrefix()} with their own command tail.
 */
abstract class AbstractSudoCommand extends Command
{
    protected ?string $targetUser = null;

    public function setTargetUser(string $user) : void
    {
        $this->targetUser = $user;
    }

    /**
     * Validate that the target user is a sane POSIX login name. The regex is
     * the same one used in BackupRestorer, PoolConfigWriter and the site
     * controllers — keep it in lockstep with those callers.
     */
    protected function assertSafeUser() : void
    {
        if (null === $this->targetUser || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $this->targetUser)) {
            throw new \RuntimeException(sprintf(
                '%s: targetUser is missing or not a valid POSIX login name.',
                static::class
            ));
        }
    }

    /**
     * Build the `/usr/bin/sudo -n -u <user>` prefix with the user shell-escaped.
     * Callers concatenate their own command tail (e.g. `' /bin/tee ...'`).
     */
    protected function sudoPrefix() : string
    {
        $this->assertSafeUser();
        return sprintf('/usr/bin/sudo -n -u %s', escapeshellarg((string) $this->targetUser));
    }

    /**
     * Default success heuristic: empty output is success; otherwise the output
     * is sniffed for the keywords that the underlying tools (sudo, tee, mv,
     * rm, rmdir, mkdir) emit on failure.
     *
     * Subclasses may override this if they need a different verdict.
     */
    public function isSuccessful() : bool
    {
        $output = strtolower((string) $this->getOutput());
        if ('' === trim($output)) {
            return true;
        }
        foreach (['denied', 'no such', 'error', 'cannot', 'not empty'] as $needle) {
            if (false !== strpos($output, $needle)) {
                return false;
            }
        }
        return true;
    }
}
