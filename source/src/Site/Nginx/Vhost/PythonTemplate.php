<?php

namespace App\Site\Nginx\Vhost;

use App\Site\Nginx\Vhost\Processor\PythonAppPort as PythonAppPortProcessor;
class PythonTemplate extends Template
{
    protected function init() : void
    {
        parent::init();
        $pythonAppPortProcessor = new PythonAppPortProcessor();
        $this->addProcessor($pythonAppPortProcessor);
    }
}