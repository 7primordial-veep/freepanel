<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;
class LocaleListener implements EventSubscriberInterface
{
    private array $locales = [];
    private string $defaultLocale;
    private string $currentLocale;
    public function __construct(array $locales, $defaultLocale = "en")
    {
        $this->locales = $locales;
        $this->defaultLocale = $defaultLocale;
    }
    public function onKernelRequest(RequestEvent $event) : void
    {
        $request = $event->getRequest();
        $this->currentLocale = $this->defaultLocale;
        if (!($locale = $request->get("locale"))) {
            $currentLocale = $request->cookies->get("locale");
            if (false === is_null($currentLocale) && true === isset($this->locales[$currentLocale])) {
                $this->currentLocale = $currentLocale;
            }
        }
        if (false === is_null($locale) && true === isset($this->locales[$locale])) {
            $this->currentLocale = $locale;
        }
        $request->setLocale($this->currentLocale);
        $request->attributes->set("locales", $this->locales);
    }
    public function onKernelResponse(ResponseEvent $event) : void
    {
        $response = $event->getResponse();
        if (true === isset($this->currentLocale) && false === is_null($this->currentLocale)) {
            $cookie = new Cookie("locale", $this->currentLocale);
            $response->headers->setCookie($cookie);
        }
    }
    public static function getSubscribedEvents() : array
    {
        return [KernelEvents::REQUEST => [["onKernelRequest", 17]], KernelEvents::RESPONSE => "onKernelResponse"];
    }
}