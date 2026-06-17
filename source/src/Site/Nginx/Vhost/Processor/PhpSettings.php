<?php

namespace App\Site\Nginx\Vhost\Processor;

class PhpSettings extends Processor
{
    protected string $placeholder = "{{php_settings}}";
    public function process(string $content) : string
    {
        $siteUser = $this->site->getUser();
        $phpSettings = $this->site->getPhpSettings();
        $errorLog = sprintf("/home/%s/logs/php/error.log", $siteUser);
        $varnishCache = $this->site->getVarnishCache();
        $configurationSettings = ["error_log" => $errorLog, "memory_limit" => $phpSettings->getMemoryLimit(), "max_execution_time" => $phpSettings->getMaxExecutionTime(), "max_input_time" => $phpSettings->getMaxInputTime(), "max_input_vars" => $phpSettings->getMaxInputVars(), "post_max_size" => $phpSettings->getPostMaxSize(), "upload_max_filesize" => $phpSettings->getUploadMaxFileSize()];
        if (true === $varnishCache) {
            $varnishCacheSettings = $this->site->getVarnishCacheSettings();
            if (true === isset($varnishCacheSettings["enabled"]) && true === $varnishCacheSettings["enabled"]) {
                $varnishControllerFile = sprintf("/home/%s/.varnish-cache/controller.php", $siteUser);
                $configurationSettings["auto_prepend_file"] = $varnishControllerFile;
            }
        }
        $placeholderValue = '';
        foreach ($configurationSettings as $key => $value) {
            $placeholderValue .= PHP_EOL . sprintf("%s=%s;", $key, $value);
        }
        $additionalConfigurations = $phpSettings->getAdditionalConfiguration();
        if (false === empty($additionalConfigurations)) {
            $placeholderValue .= PHP_EOL . $additionalConfigurations;
        }
        $content = $this->replace($placeholderValue, $content);
        return $content;
    }
}