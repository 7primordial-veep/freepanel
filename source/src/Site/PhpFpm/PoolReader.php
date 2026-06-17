<?php

namespace App\Site\PhpFpm;

class PoolReader
{
    const IGNORED_POOLS = ["global.conf"];
    private array $pools = [];
    private string $directory;
    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }
    public function getPools() : array
    {
        foreach (new \DirectoryIterator($this->directory) as $fileInfo) {
            $filename = $fileInfo->getFilename();
            if (!(false === $fileInfo->isDot() && false === in_array($filename, self::IGNORED_POOLS))) {
                continue;
            }
            $file = $fileInfo->getPathname();
            $pool = $this->parsePool($file);
            if (!(false === is_null($pool))) {
                continue;
            }
            $this->pools[] = $pool;
        }
        return $this->pools;
    }
    private function parsePool($file) : ?Pool
    {
        $parser = new PoolParser($file);
        $pool = $parser->parse();
        return $pool;
    }
}