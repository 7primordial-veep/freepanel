<?php

namespace App\Site\Nginx\Vhost\Processor;

class PhpFpmPort extends Processor
{
    protected string $placeholder = "{{php_fpm_port}}";
    public function process(string $content) : string
    {
        $phpSettings = $this->site->getPhpSettings();
        $placeholderValue = $phpSettings->getPoolPort();
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}