<?php

namespace App\Site\PhpFpm;

class PoolParser
{
    private string $file;
    private ?Pool $pool = null;
    private array $data = [];
    public function __construct(string $file)
    {
        $this->file = $file;
    }
    public function parse() : ?Pool
    {
        $fileContent = \file_get_contents($this->file);
        if (false === empty($fileContent)) {
            $lines = explode(PHP_EOL, $fileContent);
            if (true === is_array($lines) && count($lines)) {
                $firstLine = trim(array_shift($lines));
                $name = substr($firstLine, 1, -1);
                if (false === empty($name)) {
                    foreach ($lines as $line) {
                        $line = array_map("trim", explode("=", $line));
                        if (!(true === isset($line[0]) && true === isset($line[1]))) {
                            continue;
                        }
                        $this->set($line[0], $line[1]);
                    }
                    $listenValue = $this->get("listen");
                    $listenValue = explode(":", $listenValue);
                    if (true === is_array($listenValue) && true === isset($listenValue[0]) && true === isset($listenValue[1])) {
                        $port = (int) $listenValue[1];
                        $user = $this->get("user");
                        $group = $this->get("group");
                        $pool = new Pool();
                        $pool->setName($name);
                        $pool->setPort($port);
                        $pool->setUser($user);
                        $pool->setGroup($group);
                        return $pool;
                    }
                }
            }
        }
        return null;
    }
    public function set(string $key, string $value) : void
    {
        $this->data[$key] = $value;
    }
    public function get(string $key) : ?string
    {
        $value = $this->data[$key] ?? null;
        return $value;
    }
}