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
use App\Gce\Snapshot;
use App\Event\EventQueue;
class GceController extends Controller
{
    public function settings(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_GCE != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $serviceAccountKeys = ["serviceAccountKeys" => $configManager->get("gce_service_account_keys")];
        $snapshotsSettings = ["automaticSnapshots" => (bool) $configManager->get("gce_automatic_snapshots"), "frequency" => false === is_null($configManager->get("gce_snapshots_frequency")) ? (int) $configManager->get("gce_snapshots_frequency") : 12, "retentionPeriod" => false === is_null($configManager->get("gce_snapshots_retention_period")) ? (int) $configManager->get("gce_snapshots_retention_period") : 7];
        $serviceAccountKeysForm = $this->createServiceAccountKeysForm($serviceAccountKeys);
        $snapshotsSettingsForm = $this->createSnapshotsSettingsForm($snapshotsSettings);
        if (true === $request->isMethod("POST")) {
            $serviceAccountKeysForm->handleRequest($request);
            $snapshotsSettingsForm->handleRequest($request);
            if (true === $serviceAccountKeysForm->isSubmitted()) {
                $response = $this->handleServiceAccountKeysForm($request, $serviceAccountKeysForm, $configManager);
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
        $response = $this->render("Admin/Gce/Settings/index.html.twig", ["serviceAccountKeysForm" => $serviceAccountKeysForm->createView(), "snapshotsSettingsForm" => $snapshotsSettingsForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createServiceAccountKeysForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminGceServiceAccountKeysType", $data, ["action" => $this->generateUrl("clp_admin_gce_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleServiceAccountKeysForm(Request $request, Form $form, ConfigManager $configManager)
    {
        $serviceAccountKeys = trim($form->get("serviceAccountKeys")->getData());
        $serviceAccountKeysValid = $this->validateServiceAccountKeys($request, $serviceAccountKeys);
        if (false === $serviceAccountKeysValid) {
            $form->addError(new FormError($this->translator->trans("Server Account Keys are not valid.")));
        }
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $configManager->set("gce_service_account_keys", $serviceAccountKeys);
                EventQueue::addEvent(EventQueue::EVENT_GCE_SERVICE_ACCOUNTS_UPDATE, $user, [], $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Service Account Keys have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_gce_settings"));
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
        $form = $this->createForm("App\\Form\\AdminGceSnapshotsSettingsType", $data, ["action" => $this->generateUrl("clp_admin_gce_settings"), "method" => "POST", "attr" => []]);
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
                $configManager->set("gce_automatic_snapshots", $automaticSnapshots);
                $configManager->set("gce_snapshots_frequency", $frequency);
                $configManager->set("gce_snapshots_retention_period", $retentionPeriod);
                $eventData = ["automaticSnapshots" => $automaticSnapshots, "frequency" => $frequency, "retentionPeriod" => $retentionPeriod];
                EventQueue::addEvent(EventQueue::EVENT_GCE_SNAPSHOTS_SETTINGS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshots Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_gce_settings"));
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
        if (CloudPanelEnvironment::CLOUD_PROVIDER_GCE != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $response = $this->render("Admin/Gce/Snapshots/index.html.twig", ["formErrors" => $this->formErrors]);
        return $response;
    }
    public function loadSnapshots(Request $request, ConfigManager $configManager, Twig $twig) : Response
    {
        $data = [];
        if (true === $request->isXmlHttpRequest()) {
            try {
                $user = $this->getUser();
                $snapshots = [];
                $serviceAccountKeys = $configManager->get("gce_service_account_keys");
                if (false === empty($serviceAccountKeys)) {
                    $instance = $request->attributes->get("instance");
                    $gceClient = $instance->getGceClient();
                    $snapshots = $gceClient->getSnapshots();
                    usort($snapshots, function ($a, $b) {
                        return $a->getCreatedAt() < $b->getCreatedAt();
                    });
                }
                $snapshotsHtml = $twig->render("Admin/Gce/Snapshots/snapshots-list.html.twig", ["user" => $user, "snapshots" => $snapshots]);
                $data["snapshotsHtml"] = $snapshotsHtml;
            } catch (\Exception $e) {
                $errorMessage = $twig->render("Admin/Gce/Partial/error-message.html.twig", ["errorMessage" => $e->getMessage()]);
                $data["errorMessage"] = $errorMessage;
            }
        }
        return $this->json($data);
    }
    public function createSnapshot(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_GCE != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $data = [];
        $session = $request->getSession();
        if (false === $request->isMethod("POST")) {
            try {
                $instance = $request->attributes->get("instance");
                $gceClient = $instance->getGceClient();
                $instance = $gceClient->getInstance();
                $data["name"] = $instance->getName();
            } catch (\Exception $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("Service Account Keys are not valid."));
                $response = $this->redirect($this->generateUrl("clp_admin_gce_settings"));
                return $response;
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
        $response = $this->render("Admin/Gce/Snapshots/create.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createSnapshotForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminGceSnapshotType", $data, ["action" => $this->generateUrl("clp_admin_gce_snapshot_create"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Create"]);
        return $form;
    }
    private function handleCreateSnapshotForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $snapshotName = $form->get("name")->getData();
                $instance = $request->attributes->get("instance");
                $gceClient = $instance->getGceClient();
                $gceClient->createDiskSnapshots($snapshotName, Snapshot::TYPE_MANUAL);
                $eventData = ["snapshotName" => $snapshotName];
                EventQueue::addEvent(EventQueue::EVENT_GCE_SNAPSHOT_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being created."));
                $response = $this->redirect($this->generateUrl("clp_admin_gce_snapshots"));
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
        $this->checkCsrfToken($request, "gce-snapshot-delete");
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_GCE != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $id = trim($request->get("id"));
        if (false === empty($id)) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $instance = $request->attributes->get("instance");
                $gceClient = $instance->getGceClient();
                $snapshot = $gceClient->getSnapshot($id);
                if (true === isset($snapshot) && $snapshot instanceof \Google_Service_Compute_Snapshot) {
                    $gceClient->deleteSnapshot($id);
                    $eventData = ["id" => $snapshot->getId(), "name" => $snapshot->getName(), "sourceDisk" => $snapshot->getSourceDisk()];
                    EventQueue::addEvent(EventQueue::EVENT_GCE_SNAPSHOT_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being deleted."));
                }
            } catch (\Exception $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_gce_snapshots"));
        return $response;
    }
    private function validateServiceAccountKeys(Request $request, $serviceAccountKeys) : bool
    {
        try {
            $instance = $request->attributes->get("instance");
            $serviceAccountKeysValid = false;
            $serviceAccountKeys = (array) json_decode($serviceAccountKeys, true);
            $gceClient = $instance->getGceClient();
            $gceClient->setAuthConfig($serviceAccountKeys);
            $instance = $gceClient->getInstance();
            if (true === isset($instance) && $instance instanceof \Google_Service_Compute_Instance) {
                $serviceAccountKeysValid = true;
            }
        } catch (\Exception $e) {
        }
        return $serviceAccountKeysValid;
    }
}