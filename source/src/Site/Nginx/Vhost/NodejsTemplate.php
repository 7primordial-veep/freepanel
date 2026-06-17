<?php

namespace App\Site\Nginx\Vhost;

use App\Site\Nginx\Vhost\Processor\NodejsAppPort as NodejsAppPortProcessor;
class NodejsTemplate extends Template
{
    protected function init() : void
    {
        parent::init();
        $nodejsAppPortProcessor = new NodejsAppPortProcessor();
        $this->addProcessor($nodejsAppPortProcessor);
    }
}