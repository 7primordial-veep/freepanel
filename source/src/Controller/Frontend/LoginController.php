<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use App\Entity\User;
class LoginController extends Controller
{
    public function login(Request $request, AuthenticationUtils $authenticationUtils) : Response
    {
        $authenticationError = $authenticationUtils->getLastAuthenticationError();
        $response = $this->render("Frontend/Login/login.html.twig", ["authenticationError" => $authenticationError]);
        return $response;
    }
    public function loginMfa(Request $request) : Response
    {
        $user = $this->getUser();
        $session = $request->getSession();
        $mfaAuthenticated = $session->get("mfaAuthenticated");
        if (false === $user->hasMfaEnabled() || $mfaAuthenticated !== false) {
            $session->remove("mfaAuthenticated");
            $response = $this->redirect($this->generateUrl("clp_login"));
            return $response;
        }
        $form = $this->createLoginMfaForm($user);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            $isSubmitted = $form->isSubmitted();
            if (true === $isSubmitted) {
                $response = $this->handleLoginMfaCodeForm($request, $form);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Frontend/Login/login-mfa.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createLoginMfaForm(User $user) : Form
    {
        $form = $this->createForm("App\\Form\\LoginMfaType", $user, ["action" => $this->generateUrl("clp_login_mfa"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-login"], "label" => "Submit"]);
        return $form;
    }
    private function handleLoginMfaCodeForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $session->set("mfaAuthenticated", true);
                $response = $this->redirect($this->generateUrl("clp_sites"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function logout() : void
    {
    }
}