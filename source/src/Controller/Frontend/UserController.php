<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use App\Entity\Manager\UserManager;
use App\Entity\Manager\TimezoneManager;
use App\Security\Authenticator\MfaAuthenticator;
use App\Entity\User;
class UserController extends Controller
{
    public function adminUserCreation(Request $request, UserManager $userManager, TimezoneManager $timezoneManager) : Response
    {
        $users = $userManager->findAll();
        if (count($users)) {
            $response = $this->redirect($this->generateUrl("clp_login"));
            return $response;
        }
        $timezone = $timezoneManager->findOneByName("Europe/Berlin");
        $user = $userManager->createEntity();
        $user->setRole(User::ROLE_ADMIN);
        $user->setStatus(User::STATUS_ACTIVE);
        $user->setTimezone($timezone);
        $form = $this->createAdminUserCreationForm($user);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            $isSubmitted = $form->isSubmitted();
            if (true === $isSubmitted) {
                $response = $this->handleAdminUserCreationForm($request, $form, $userManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Frontend/User/admin-user-creation.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createAdminUserCreationForm(User $user) : Form
    {
        $form = $this->createForm("App\\Form\\UserAdminUserCreationType", $user, ["action" => $this->generateUrl("clp_admin_user_creation"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue btn-create-user"], "label" => "Create User"]);
        return $form;
    }
    private function handleAdminUserCreationForm(Request $request, Form $form, UserManager $userManager)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $form->getData();
                $userManager->updateUser($user);
                $response = $this->redirect($this->generateUrl("clp_login"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function settings(Request $request, UserManager $userManager) : Response
    {
        $user = $this->getUser();
        $form = $this->createUserSettingsForm($user);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            $isSubmitted = $form->isSubmitted();
            if (true === $isSubmitted) {
                $response = $this->handleUserSettingsForm($request, $form, $userManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Frontend/User/Account/settings.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createUserSettingsForm(User $user) : Form
    {
        $form = $this->createForm("App\\Form\\UserSettingsType", $user, ["action" => $this->generateUrl("clp_user_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleUserSettingsForm(Request $request, Form $form, UserManager $userManager)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $form->getData();
                $plainPassword = $user->getPlainPassword();
                if (false === empty($plainPassword)) {
                    $userManager->updateUser($user, true, true);
                } else {
                    $userManager->updateUser($user);
                }
                $session->getFlashBag()->set("success", $this->translator->trans("The account information has been saved."));
                $response = $this->redirect($this->generateUrl("clp_user_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function security(Request $request) : Response
    {
        $user = $this->getUser();
        $response = $this->render("Frontend/User/Account/security.html.twig", ["user" => $user]);
        return $response;
    }
    public function enableMfa(Request $request, UserManager $userManager) : Response
    {
        $user = $this->getUser();
        if (true === $user->hasMfaEnabled()) {
            $response = $this->redirect($this->generateUrl("clp_user_security"));
            return $response;
        }
        $mfaAuthenticator = new MfaAuthenticator();
        $qrCodeLink = $mfaAuthenticator->getQrCodeLink($user->getMfaSecret(), $user->getUserName(), "CloudPanel");
        $form = $this->createMfaCodeForm($user);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            $isSubmitted = $form->isSubmitted();
            if (true === $isSubmitted) {
                $response = $this->handleMfaCodeForm($request, $form, $userManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Frontend/User/Account/enable-mfa.html.twig", ["qrCodeLink" => $qrCodeLink, "user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createMfaCodeForm(User $user) : Form
    {
        $form = $this->createForm("App\\Form\\UserSecurityMfaCodeType", $user, ["action" => $this->generateUrl("clp_user_security_enable_mfa"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg btn-submit-mfa-code"], "label" => "Save"]);
        return $form;
    }
    private function handleMfaCodeForm(Request $request, Form $form, UserManager $userManager)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $user->setMfa(true);
                $userManager->updateUser($user);
                $session->getFlashBag()->set("success", $this->translator->trans("Two-Factor Authentication has been enabled."));
                $response = $this->redirect($this->generateUrl("clp_user_security"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function disableMfa(Request $request, UserManager $userManager) : Response
    {
        try {
            $this->checkCsrfToken($request, "disable-mfa");
            $session = $request->getSession();
            $user = $this->getUser();
            $user->setMfa(false);
            $userManager->updateUser($user);
            $session->getFlashBag()->set("success", $this->translator->trans("Two-Factor Authentication has been disabled."));
            $response = $this->redirect($this->generateUrl("clp_user_security"));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            $response = $this->redirect($this->generateUrl("clp_user_security"));
        }
        return $response;
    }
}