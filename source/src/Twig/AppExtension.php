<?php

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Environment;
use Twig\TwigFunction;
class AppExtension extends AbstractExtension
{
    const GRAVATAR_URL = "https://www.gravatar.com/avatar/";
    private $generator;
    public function __construct(UrlGeneratorInterface $generator)
    {
        $this->generator = $generator;
    }
    public function getFunctions() : array
    {
        return [new TwigFunction("formatEventData", [$this, "formatEventData"]), new TwigFunction("gravatar", [$this, "gravatar"]), new TwigFunction("isMGT", [$this, "isMGT"])];
    }
    public function formatEventData(array $eventData) : mixed
    {
        $eventData = print_r($eventData, true);
        return $eventData;
    }
    public function gravatar($email) : string
    {
        $gravatarUrl = sprintf("%s/%s/?s=35&d=mp", rtrim(self::GRAVATAR_URL, "/"), md5(strtolower($email)));
        return $gravatarUrl;
    }
    public function isMGT() : bool
    {
        $isMGT = false;
        if (true === isset($_ENV["MGT"]) && false === empty($_ENV["MGT"])) {
            $isMGT = true;
        }
        return $isMGT;
    }
    public function initRuntime(Environment $environment)
    {
    }
    public function getGlobals()
    {
    }
    public function getName()
    {
    }
}