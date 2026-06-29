<?php

namespace App\System\Command\Util;

final class SystemdUnitName
{
    public static function assertSafe(string $name) : void
    {
        if ('' === $name) {
            throw new \RuntimeException('SystemdUnitName: empty file name.');
        }
        if ('/' === $name[0]) {
            throw new \RuntimeException('SystemdUnitName: absolute paths rejected.');
        }
        if (false !== strpos($name, '..')) {
            throw new \RuntimeException('SystemdUnitName: ".." not allowed in file name.');
        }
        // Allow at most one level of "<unit>.<type>.d/<file>.conf" for drop-ins.
        if (false !== strpos($name, '/') && 1 !== substr_count($name, '/')) {
            throw new \RuntimeException('SystemdUnitName: only single-level drop-in subdir allowed.');
        }
        if (!preg_match('#^[A-Za-z0-9._@-]+(?:/[A-Za-z0-9._@-]+)?$#', $name)) {
            throw new \RuntimeException('SystemdUnitName: invalid characters in file name.');
        }
    }
}
