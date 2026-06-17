<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use App\Entity\Manager\UserManager;
class RequestListener implements EventSubscriberInterface
{
    const ROUTE_ADMIN_USER_CREATION = "clp_admin_user_creation";
    private Router $router;
    private UserManager $userManager;
    private array $whitelistedRoutes = ["clp_home", "clp_login_mfa", "clp_login_mfa", "_wdt"];
    public function __construct(Router $router, UserManager $userManager)
    {
        $this->router = $router;
        $this->userManager = $userManager;
    }
    public function onKernelRequest(RequestEvent $event) : void
    {
        if (false === $event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $session = $request->getSession();
        $requestAttributes = $request->attributes;
        $route = $requestAttributes->get("_route");
        $numberOfUsers = $this->userManager->countAll();
        if (0 == $numberOfUsers && self::ROUTE_ADMIN_USER_CREATION != $route && "clp_api" != substr($route, 0, 7)) {
            $redirectUrl = $this->router->generate(self::ROUTE_ADMIN_USER_CREATION);
            $redirect = new RedirectResponse($redirectUrl);
            $event->setResponse($redirect);
        } else {
            $mfaAuthenticated = $session->get("mfaAuthenticated");
            if (false === in_array($route, $this->whitelistedRoutes) && false === is_null($mfaAuthenticated) && false === $mfaAuthenticated) {
                $redirectUrl = $this->router->generate("clp_login_mfa");
                $redirect = new RedirectResponse($redirectUrl);
                $event->setResponse($redirect);
            }
        }
    }
    public static function getSubscribedEvents() : array
    {
        return [KernelEvents::REQUEST => [["onKernelRequest", 4]]];
    }
}