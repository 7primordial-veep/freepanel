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
class VultrController extends Controller
{
    public function settings(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_VULTR != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $apiKey = ["apiKey" => true === empty($configManager->get("vultr_api_key")) ? '' : sprintf("%s********************", substr($configManager->get("vultr_api_key"), 0, -20))];
        $snapshotsSettings = ["automaticSnapshots" => (bool) $configManager->get("vultr_automatic_snapshots"), "frequency" => false === is_null($configManager->get("vultr_snapshots_frequency")) ? (int) $configManager->get("vultr_snapshots_frequency") : 12, "retentionPeriod" => false === is_null($configManager->get("vultr_snapshots_retention_period")) ? (int) $configManager->get("vultr_snapshots_retention_period") : 7];
        $apiKeyForm = $this->createApiKeyForm($apiKey);
        $snapshotsSettingsForm = $this->createSnapshotsSettingsForm($snapshotsSettings);
        if (true === $request->isMethod("POST")) {
            $apiKeyForm->handleRequest($request);
            $snapshotsSettingsForm->handleRequest($request);
            if (true === $apiKeyForm->isSubmitted()) {
                $response = $this->handleApiKeyForm($request, $apiKeyForm, $configManager);
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
        $response = $this->render("Admin/Vultr/Settings/index.html.twig", ["apiKeyForm" => $apiKeyForm->createView(), "snapshotsSettingsForm" => $snapshotsSettingsForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createApiKeyForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminVultrApiKeyType", $data, ["action" => $this->generateUrl("clp_admin_vultr_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleApiKeyForm(Request $request, Form $form, ConfigManager $configManager)
    {
        $apiKey = $form->get("apiKey")->getData();
        $isApiKeyValid = $this->validateApiKey($request, $apiKey);
        if (false === $isApiKeyValid) {
            $form->addError(new FormError($this->translator->trans("Api Key is not valid.")));
        }
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $configManager->set("vultr_api_key", $apiKey);
                $eventData = ["apiKey" => sprintf("%s****", substr($apiKey, 0, -4))];
                EventQueue::addEvent(EventQueue::EVENT_VULTR_API_KEY_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Api Key has been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_vultr_settings"));
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
        $form = $this->createForm("App\\Form\\AdminVultrSnapshotsSettingsType", $data, ["action" => $this->generateUrl("clp_admin_vultr_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
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
                $configManager->set("vultr_automatic_snapshots", $automaticSnapshots);
                $configManager->set("vultr_snapshots_frequency", $frequency);
                $configManager->set("vultr_snapshots_retention_period", $retentionPeriod);
                $eventData = ["automaticSnapshots" => $automaticSnapshots, "frequency" => $frequency, "retentionPeriod" => $retentionPeriod];
                EventQueue::addEvent(EventQueue::EVENT_VULTR_SNAPSHOTS_SETTINGS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshots Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_vultr_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function snapshots(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_VULTR != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $response = $this->render("Admin/Vultr/Snapshots/index.html.twig", ["formErrors" => $this->formErrors]);
        return $response;
    }
    public function loadSnapshots(Request $request, ConfigManager $configManager, Twig $twig) : Response
    {
        $data = [];
        if (true === $request->isXmlHttpRequest()) {
            try {
                $user = $this->getUser();
                $snapshots = [];
                $apiKey = $configManager->get("vultr_api_key");
                if (false === empty($apiKey)) {
                    $instance = $request->attributes->get("instance");
                    $vultrClient = $instance->getVultrClient();
                    $snapshots = $vultrClient->getSnapshots();
                    usort($snapshots, function ($a, $b) {
                        return $a->getCreatedAt() < $b->getCreatedAt();
                    });
                }
                $snapshotsHtml = $twig->render("Admin/Vultr/Snapshots/snapshots-list.html.twig", ["user" => $user, "snapshots" => $snapshots]);
                $data["snapshotsHtml"] = $snapshotsHtml;
            } catch (\Exception $e) {
                $errorMessage = $twig->render("Admin/Vultr/Partial/error-message.html.twig", ["errorMessage" => $e->getMessage()]);
                $data["errorMessage"] = $errorMessage;
            }
        }
        return $this->json($data);
    }
    public function createSnapshot(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_VULTR != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $data = [];
        if (false === $request->isMethod("POST")) {
            try {
                $instance = $request->attributes->get("instance");
                $vultrClient = $instance->getVultrClient();
                $vultrInstance = $vultrClient->getInstance();
                $data["name"] = $vultrInstance->getLabel();
            } catch (\Exception $e) {
                if (401 == $e->getCode()) {
                    $session = $request->getSession();
                    $session->getFlashBag()->set("danger", $this->translator->trans("Api Key is not valid."));
                    $response = $this->redirect($this->generateUrl("clp_admin_vultr_settings"));
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
        $response = $this->render("Admin/Vultr/Snapshots/create.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createSnapshotForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminVultrSnapshotType", $data, ["action" => $this->generateUrl("clp_admin_vultr_snapshot_create"), "method" => "POST", "attr" => []]);
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
                $vultrClient = $instance->getVultrClient();
                $vultrInstance = $vultrClient->getInstance();
                $snapshotName = sprintf("%s-%s", $form->get("name")->getData(), $vultrInstance->getId());
                $vultrClient->createSnapshot($snapshotName);
                $eventData = ["snapshotName" => $snapshotName];
                EventQueue::addEvent(EventQueue::EVENT_VULTR_SNAPSHOT_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being created."));
                $response = $this->redirect($this->generateUrl("clp_admin_vultr_snapshots"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteSnapshot(Request $request, ConfigManager $configManager) : Response
    {
        $this->checkCsrfToken($request, "vultr-snapshot-delete");
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_VULTR != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $id = trim($request->get("id"));
        if (false === empty($id)) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $instance = $request->attributes->get("instance");
                $vultrClient = $instance->getVultrClient();
                $snapshot = $vultrClient->getSnapshot($id);
                if (false === is_null($snapshot)) {
                    $vultrClient->deleteSnapshot($id);
                    $eventData = ["snapshotId" => $snapshot->getId(), "snapshotName" => $snapshot->getDescription()];
                    EventQueue::addEvent(EventQueue::EVENT_VULTR_SNAPSHOT_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being deleted."));
                }
            } catch (\Exception $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_vultr_snapshots"));
        return $response;
    }
    private function validateApiKey(Request $request, $apiKey) : bool
    {
        try {
            $isApiKeyValid = false;
            $instance = $request->attributes->get("instance");
            $vultrClient = clone $instance->getVultrClient();
            $vultrClient->unsetHttpClient();
            $vultrClient->setApiKey($apiKey);
            $instanceData = $vultrClient->getInstanceData();
            if (false === empty($instanceData)) {
                $isApiKeyValid = true;
            }
        } catch (\Exception $e) {
        }
        return $isApiKeyValid;
    }
}