<?php

namespace App\Site\Nginx\Vhost\Processor;

class PythonAppPort extends Processor
{
    protected string $placeholder = "{{app_port}}";
    public function process(string $content) : string
    {
        $pythonSettings = $this->site->getPythonSettings();
        $placeholderValue = $pythonSettings->getPort();
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}