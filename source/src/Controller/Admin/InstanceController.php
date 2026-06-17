<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use App\Entity\Manager\ConfigManager;
use App\Entity\Manager\TimezoneManager;
use App\Controller\Controller;
use App\Event\EventQueue;
class InstanceController extends Controller
{
    public function services(Request $request) : Response
    {
        $instance = $request->attributes->get("instance");
        $services = $instance->getServices();
        $response = $this->render("Admin/Instance/services.html.twig", ["services" => $services]);
        return $response;
    }
    public function restartService(Request $request) : Response
    {
        $instance = $request->attributes->get("instance");
        $serviceName = trim($request->get("name"));
        $services = $instance->getServices();
        $this->checkCsrfToken($request, "service-restart");
        if (true === isset($services[$serviceName])) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $service = $services[$serviceName];
                $instance->restartService($service);
                $eventData = ["service" => $serviceName];
                EventQueue::addEvent(EventQueue::EVENT_SERVICE_RESTART, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Service will restart shortly."));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_services"));
        return $response;
    }
    public function settings(Request $request, ConfigManager $configManager, TimezoneManager $timezoneManager) : Response
    {
        $proftpdSettings = ["masqueradeAddress" => $configManager->get("masquerade_address")];
        $timezoneConfigValue = $configManager->get("timezone");
        $timezone = $timezoneManager->findOneByName($timezoneConfigValue);
        $instanceSettings = ["timezone" => $timezone];
        $proftpdSettingsForm = $this->createProftpdSettingsForm($proftpdSettings);
        $instanceSettingsForm = $this->createInstanceSettingsForm($instanceSettings);
        if (true === $request->isMethod("POST")) {
            $response = null;
            $proftpdSettingsForm->handleRequest($request);
            $instanceSettingsForm->handleRequest($request);
            if (true === $proftpdSettingsForm->isSubmitted()) {
                $response = $this->handleProftpdSettingsForm($request, $proftpdSettingsForm, $configManager);
            }
            if (true === $instanceSettingsForm->isSubmitted()) {
                $response = $this->handleInstanceSettingsForm($request, $instanceSettingsForm, $configManager);
            }
            if (false === is_null($response)) {
                return $response;
            }
        }
        $response = $this->render("Admin/Instance/settings.html.twig", ["proftpdSettingsForm" => $proftpdSettingsForm->createView(), "instanceSettingsForm" => $instanceSettingsForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createProftpdSettingsForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminProftpdSettingsType", $data, ["action" => $this->generateUrl("clp_admin_instance_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createInstanceSettingsForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminInstanceSettingsType", $data, ["action" => $this->generateUrl("clp_admin_instance_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleProftpdSettingsForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $masqueradeAddress = trim($form->get("masqueradeAddress")->getData());
                $instance = $request->attributes->get("instance");
                $instance->setProftpdMasqueradeAddress($masqueradeAddress);
                $configManager->set("masquerade_address", $masqueradeAddress);
                $eventData = ["masqueradeAddress" => $masqueradeAddress];
                EventQueue::addEvent(EventQueue::EVENT_PROFTPD_MASQUERADE_ADDRESS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("MasqueradeAddress has been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_instance_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleInstanceSettingsForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $timezone = trim($form->get("timezone")->getData());
                $instance = $request->attributes->get("instance");
                $instance->setTimezone($timezone);
                $configManager->set("timezone", $timezone);
                $eventData = ["timezone" => $timezone];
                EventQueue::addEvent(EventQueue::EVENT_INSTANCE_TIMEZONE_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Timezone has been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_instance_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function restart(Request $request) : Response
    {
        $user = $this->getUser();
        $session = $request->getSession();
        $this->checkCsrfToken($request, "instance-restart");
        $instance = $request->attributes->get("instance");
        $instance->reboot();
        EventQueue::addEvent(EventQueue::EVENT_INSTANCE_REBOOT, $user, [], $request);
        $session->getFlashBag()->set("success", $this->translator->trans("Instance will reboot shortly."));
        $response = $this->redirectToReferer($request);
        return $response;
    }
}