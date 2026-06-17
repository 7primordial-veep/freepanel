<?php

namespace App\EventListener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use App\Event\EventQueue;
class LoginListener
{
    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event) : void
    {
        $token = $event->getAuthenticationToken();
        $user = $token->getUser();
        $request = $event->getRequest();
        EventQueue::addEvent(EventQueue::EVENT_LOGIN, $user, [], $request);
        $session = $request->getSession();
        if (true === $user->hasMfaEnabled()) {
            $session->set("mfaAuthenticated", false);
        } else {
            $session->remove("mfaAuthenticated");
        }
    }
}