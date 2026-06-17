<?php

namespace App\Site\PhpFpm;

class PoolBuilder
{
    private string $template = "[{{name}}]\nlisten = 127.0.0.1:{{port}}\nuser = {{user}}\ngroup = {{group}}\nlisten.allowed_clients = 127.0.0.1\npm = ondemand\npm.max_children = 250\npm.process_idle_timeout = 10s\npm.max_requests = 100\nlisten.backlog = 65535\npm.status_path = /status\nrequest_terminate_timeout = 7200s\nrlimit_files = 131072\nrlimit_core = unlimited\ncatch_workers_output = yes";
    public function create(Pool $pool) : string
    {
        $name = $pool->getName();
        $user = $pool->getUser();
        $group = $pool->getGroup();
        $port = $pool->getPort();
        $pool = str_replace(["{{name}}", "{{port}}", "{{user}}", "{{group}}"], [$name, $port, $user, $group], $this->template);
        return $pool;
    }
}