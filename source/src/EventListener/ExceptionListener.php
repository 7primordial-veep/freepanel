<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
class ExceptionListener
{
    private Router $router;
    private TranslatorInterface $translator;
    public function __construct(Router $router, TranslatorInterface $translator)
    {
        $this->router = $router;
        $this->translator = $translator;
    }
    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        if ($exception instanceof NotFoundHttpException) {
            $url = $this->router->generate("clp_login");
            $response = new RedirectResponse($url, RedirectResponse::HTTP_FOUND);
            $event->setResponse($response);
        }
        if ($exception instanceof AccessDeniedHttpException) {
            $request = $event->getRequest();
            $session = $request->getSession();
            $session->getFlashBag()->set("danger", $this->translator->trans("Access Denied."));
            $url = $this->router->generate("clp_sites");
            $response = new RedirectResponse($url);
            $event->setResponse($response);
        }
    }
}