<?php

namespace App\Site\Nginx\Vhost\Processor;

class RootDirectory extends Processor
{
    protected string $placeholder = "{{root}}";
    public function process(string $content) : string
    {
        $siteUser = $this->site->getUser();
        $rootDirectory = $this->site->getRootDirectory();
        $placeholderValue = rtrim(sprintf("root /home/%s/htdocs/%s;", $siteUser, $rootDirectory), "/");
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}