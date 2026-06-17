<?php

namespace App\Site;

class StaticSite extends Site
{
    const TYPE = "static";
    protected string $type = self::TYPE;
}