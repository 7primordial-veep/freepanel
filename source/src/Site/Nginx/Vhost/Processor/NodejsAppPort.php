<?php

namespace App\Site\Nginx\Vhost\Processor;

class NodejsAppPort extends Processor
{
    protected string $placeholder = "{{app_port}}";
    public function process(string $content) : string
    {
        $nodejsSettings = $this->site->getNodejsSettings();
        $placeholderValue = $nodejsSettings->getPort();
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}