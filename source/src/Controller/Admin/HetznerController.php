<?php

namespace App\Controller\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use Twig\Environment as Twig;
use App\Controller\Controller;
use App\Entity\Manager\ConfigManager;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Event\EventQueue;
class HetznerController extends Controller
{
    public function settings(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $apiKey = ["apiToken" => true === empty($configManager->get("hetzner_api_token")) ? '' : sprintf("%s********************", substr($configManager->get("hetzner_api_token"), 0, -20))];
        $snapshotsSettings = ["automaticSnapshots" => (bool) $configManager->get("hetzner_automatic_snapshots"), "frequency" => false === is_null($configManager->get("hetzner_snapshots_frequency")) ? (int) $configManager->get("hetzner_snapshots_frequency") : 12, "retentionPeriod" => false === is_null($configManager->get("hetzner_snapshots_retention_period")) ? (int) $configManager->get("hetzner_snapshots_retention_period") : 7];
        $apiTokenForm = $this->createApiTokenForm($apiKey);
        $snapshotsSettingsForm = $this->createSnapshotsSettingsForm($snapshotsSettings);
        if (true === $request->isMethod("POST")) {
            $apiTokenForm->handleRequest($request);
            $snapshotsSettingsForm->handleRequest($request);
            if (true === $apiTokenForm->isSubmitted()) {
                $response = $this->handleApiTokenForm($request, $apiTokenForm, $configManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
            if (true === $snapshotsSettingsForm->isSubmitted()) {
                $response = $this->handleSnapshotsSettingsForm($request, $snapshotsSettingsForm, $configManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Hetzner/Settings/index.html.twig", ["apiTokenForm" => $apiTokenForm->createView(), "snapshotsSettingsForm" => $snapshotsSettingsForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createApiTokenForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminHetznerApiTokenType", $data, ["action" => $this->generateUrl("clp_admin_hetzner_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleApiTokenForm(Request $request, Form $form, ConfigManager $configManager)
    {
        $apiToken = $form->get("apiToken")->getData();
        $isApiTokenValid = $this->validateApiToken($request, $apiToken);
        if (false === $isApiTokenValid) {
            $form->addError(new FormError($this->translator->trans("Api Token is not valid.")));
        }
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $configManager->set("hetzner_api_token", $apiToken);
                $eventData = ["apiToken" => sprintf("%s****", substr($apiToken, 0, -4))];
                EventQueue::addEvent(EventQueue::EVENT_HETZNER_API_TOKEN_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Api Token has been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_hetzner_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleSnapshotsSettingsForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $automaticSnapshots = (int) $form->get("automaticSnapshots")->getData();
                $frequency = (int) $form->get("frequency")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $configManager->set("hetzner_automatic_snapshots", $automaticSnapshots);
                $configManager->set("hetzner_snapshots_frequency", $frequency);
                $configManager->set("hetzner_snapshots_retention_period", $retentionPeriod);
                $eventData = ["automaticSnapshots" => $automaticSnapshots, "frequency" => $frequency, "retentionPeriod" => $retentionPeriod];
                EventQueue::addEvent(EventQueue::EVENT_HETZNER_SNAPSHOTS_SETTINGS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshots Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_hetzner_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function createSnapshotsSettingsForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminHetznerSnapshotsSettingsType", $data, ["action" => $this->generateUrl("clp_admin_hetzner_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    public function snapshots(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $response = $this->render("Admin/Hetzner/Snapshots/index.html.twig", ["formErrors" => $this->formErrors]);
        return $response;
    }
    public function loadSnapshots(Request $request, ConfigManager $configManager, Twig $twig) : Response
    {
        $data = [];
        if (true === $request->isXmlHttpRequest()) {
            try {
                $user = $this->getUser();
                $snapshots = [];
                $apiToken = $configManager->get("hetzner_api_token");
                if (false === empty($apiToken)) {
                    $instance = $request->attributes->get("instance");
                    $hetznerClient = $instance->getHetznerClient();
                    $snapshots = $hetznerClient->getSnapshots();
                    usort($snapshots, function ($a, $b) {
                        return $a->getCreatedAt() < $b->getCreatedAt();
                    });
                }
                $snapshotsHtml = $twig->render("Admin/Hetzner/Snapshots/snapshots-list.html.twig", ["user" => $user, "snapshots" => $snapshots]);
                $data["snapshotsHtml"] = $snapshotsHtml;
            } catch (\Exception $e) {
                $errorMessage = $twig->render("Admin/Hetzner/Partial/error-message.html.twig", ["errorMessage" => $e->getMessage()]);
                $data["errorMessage"] = $errorMessage;
            }
        }
        return $this->json($data);
    }
    public function deleteSnapshot(Request $request, ConfigManager $configManager) : Response
    {
        $this->checkCsrfToken($request, "hetzner-snapshot-delete");
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $id = trim($request->get("id"));
        if (false === empty($id)) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $instance = $request->attributes->get("instance");
                $hetznerClient = $instance->getHetznerClient();
                $snapshot = $hetznerClient->getSnapshot($id);
                if (false === is_null($snapshot)) {
                    $hetznerClient->deleteSnapshot($id);
                    $eventData = ["snapshotId" => $snapshot->getId(), "snapshotName" => $snapshot->getName()];
                    EventQueue::addEvent(EventQueue::EVENT_HETZNER_SNAPSHOT_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being deleted."));
                }
            } catch (\Exception $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_hetzner_snapshots"));
        return $response;
    }
    public function createSnapshot(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $data = [];
        if (false === $request->isMethod("POST")) {
            try {
                $instance = $request->attributes->get("instance");
                $hetznerClient = $instance->getHetznerClient();
                $data["name"] = $hetznerClient->getInstanceName();
            } catch (\Exception $e) {
                if (401 == $e->getCode()) {
                    $session = $request->getSession();
                    $session->getFlashBag()->set("danger", $this->translator->trans("Api Token is not valid."));
                    $response = $this->redirect($this->generateUrl("clp_admin_hetzner_settings"));
                    return $response;
                }
            }
        }
        $form = $this->createSnapshotForm($data);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleCreateSnapshotForm($request, $form, $configManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Hetzner/Snapshots/create.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createSnapshotForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminHetznerSnapshotType", $data, ["action" => $this->generateUrl("clp_admin_hetzner_snapshot_create"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Create"]);
        return $form;
    }
    private function handleCreateSnapshotForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $instance = $request->attributes->get("instance");
                $hetznerClient = $instance->getHetznerClient();
                $snapshotName = $form->get("name")->getData();
                $hetznerClient->createSnapshot($snapshotName);
                $eventData = ["snapshotName" => $snapshotName];
                EventQueue::addEvent(EventQueue::EVENT_HETZNER_SNAPSHOT_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being created."));
                $response = $this->redirect($this->generateUrl("clp_admin_hetzner_snapshots"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function validateApiToken(Request $request, $apiToken) : bool
    {
        try {
            $isApiTokenValid = false;
            $instance = $request->attributes->get("instance");
            $hetznerClient = clone $instance->getHetznerClient();
            $hetznerClient->unsetHttpClient();
            $hetznerClient->setToken($apiToken);
            $instanceData = $hetznerClient->getInstanceData();
            if (false === empty($instanceData)) {
                $isApiTokenValid = true;
            }
        } catch (\Exception $e) {
        }
        return $isApiTokenValid;
    }
}