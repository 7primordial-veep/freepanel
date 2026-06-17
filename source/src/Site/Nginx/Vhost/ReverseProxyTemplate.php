<?php

namespace App\Site\Nginx\Vhost;

use App\Site\Nginx\Vhost\Processor\ReverseProxyUrl as ReverseProxyUrlProcessor;
class ReverseProxyTemplate extends Template
{
    protected function init() : void
    {
        parent::init();
        $reverseProxyProcessor = new ReverseProxyUrlProcessor();
        $this->addProcessor($reverseProxyProcessor);
    }
}