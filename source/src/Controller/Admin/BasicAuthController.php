<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use App\Security\Admin\BasicAuth as AdminBasicAuth;
class BasicAuthController extends Controller
{
    public function index(Request $request) : Response
    {
        $form = $this->createBasicAuthForm();
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleBasicAuthForm($request, $form);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $adminBasicAuth = new AdminBasicAuth();
        $isBasicAuthEnabled = $adminBasicAuth->isEnabled();
        $response = $this->render("Admin/Security/Basic-Auth/index.html.twig", ["isBasicAuthEnabled" => $isBasicAuthEnabled, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createBasicAuthForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminBasicAuthType", [], ["action" => $this->generateUrl("clp_admin_basic_auth"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleBasicAuthForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $userName = $form->get("userName")->getData();
                $password = $form->get("password")->getData();
                $adminBasicAuth = new AdminBasicAuth();
                $adminBasicAuth->enable($userName, $password);
                $session->getFlashBag()->set("success", $this->translator->trans("Basic Auth has been enabled."));
                $response = $this->redirect($this->generateUrl("clp_admin_basic_auth"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function disable(Request $request) : Response
    {
        try {
            $data = [];
            $adminBasicAuth = new AdminBasicAuth();
            $isBasicAuthEnabled = $adminBasicAuth->isEnabled();
            if (true === $isBasicAuthEnabled) {
                $adminBasicAuth->disable();
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $data["errorMessage"] = $e->getMessage();
        }
        return $this->json($data);
    }
}