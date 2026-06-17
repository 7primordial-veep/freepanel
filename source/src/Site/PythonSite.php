<?php

namespace App\Site;

use App\Entity\PythonSettings;
class PythonSite extends Site
{
    private const TYPE = "python";
    protected string $type = self::TYPE;
    private ?PythonSettings $pythonSettings = null;
    public function setPythonSettings(PythonSettings $pythonSettings) : void
    {
        $this->pythonSettings = $pythonSettings;
    }
    public function getPythonSettings() : ?PythonSettings
    {
        return $this->pythonSettings;
    }
}