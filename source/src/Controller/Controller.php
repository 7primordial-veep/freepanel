<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseController;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Form\Form;
use App\Service\Logger;
use App\Util\Retry;
class Controller extends BaseController
{
    protected array $formErrors = [];
    protected Logger $logger;
    protected TranslatorInterface $translator;
    public function __construct(TranslatorInterface $translator, Logger $logger)
    {
        $this->translator = $translator;
        $this->logger = $logger;
    }
    protected function getErrorMessages(Form $form) : array
    {
        $errors = [];
        $formChildren = $form->all();
        $formErrors = $form->getErrors();
        if (count($formErrors)) {
            foreach ($formErrors as $formError) {
                $errors[] = $formError->getMessage();
            }
        }
        foreach ($formChildren as $child) {
            if (false === $child->isValid()) {
                $childErrors = $child->getErrors(true);
                foreach ($childErrors as $childError) {
                    $label = $this->translator->trans($child->getConfig()->getOption("label"));
                    if ($label) {
                        $message = sprintf("%s: %s", $label, $childError->getMessage());
                    } else {
                        $message = $childError->getMessage();
                    }
                    $errors[] = $message;
                }
            }
        }
        return $errors;
    }
    protected function redirectToReferer(Request $request) : Response
    {
        $referer = $request->headers->get("referer");
        return new RedirectResponse($referer);
    }
    protected function retry(callable $fn, $retries = 2, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
    protected function checkCsrfToken(Request $request, string $id) : void
    {
        $token = $request->query->get("token");
        $isCsrfTokenValid = $this->isCsrfTokenValid($id, $token);
        if (false === $isCsrfTokenValid) {
            throw new InvalidCsrfTokenException("The CSRF token is invalid.");
        }
    }
}