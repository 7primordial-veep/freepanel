<?php

namespace App\Site;

use App\Entity\NodejsSettings;
class NodejsSite extends Site
{
    private const TYPE = "nodejs";
    protected string $type = self::TYPE;
    private ?NodejsSettings $nodejsSettings = null;
    public function setNodejsSettings(NodejsSettings $nodejsSettings) : void
    {
        $this->nodejsSettings = $nodejsSettings;
    }
    public function getNodejsSettings() : ?NodejsSettings
    {
        return $this->nodejsSettings;
    }
}