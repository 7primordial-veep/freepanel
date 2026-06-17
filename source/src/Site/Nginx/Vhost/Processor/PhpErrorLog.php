<?php

namespace App\Site\Nginx\Vhost\Processor;

class PhpErrorLog extends Processor
{
    protected string $placeholder = "{{php_error_log}}";
    public function process(string $content) : string
    {
        $siteUser = $this->site->getUser();
        $placeholderValue = sprintf("/home/%s/logs/php/error.log", $siteUser);
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}