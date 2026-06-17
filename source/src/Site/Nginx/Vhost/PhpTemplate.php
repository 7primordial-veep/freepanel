<?php

namespace App\Site\Nginx\Vhost;

use App\Site\Nginx\Vhost\Processor\PhpFpmPort as PhpFpmPortProcessor;
use App\Site\Nginx\Vhost\Processor\PhpSettings as PhpSettingsProcessor;
use App\Site\Nginx\Vhost\Processor\PhpErrorLog as PhpErrorLogProcessor;
use App\Site\Nginx\Vhost\Processor\VarnishProxyPass as VarnishProxyPassProcessor;
class PhpTemplate extends Template
{
    protected function init() : void
    {
        parent::init();
        $phpFpmPortProcessor = new PhpFpmPortProcessor();
        $phpSettingsProcessor = new PhpSettingsProcessor();
        $phpErrorLogProcessor = new PhpErrorLogProcessor();
        $varnishProxyPassProcessor = new VarnishProxyPassProcessor();
        $this->addProcessor($phpFpmPortProcessor);
        $this->addProcessor($phpSettingsProcessor);
        $this->addProcessor($phpErrorLogProcessor);
        $this->addProcessor($varnishProxyPassProcessor);
    }
}