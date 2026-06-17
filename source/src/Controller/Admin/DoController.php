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
class DoController extends Controller
{
    public function settings(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_DO != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $accessToken = ["accessToken" => true === empty($configManager->get("do_access_token")) ? '' : sprintf("%s********************", substr($configManager->get("do_access_token"), 0, -20))];
        $snapshotsSettings = ["automaticSnapshots" => (bool) $configManager->get("do_automatic_snapshots"), "frequency" => false === is_null($configManager->get("do_snapshots_frequency")) ? (int) $configManager->get("do_snapshots_frequency") : 12, "retentionPeriod" => false === is_null($configManager->get("do_snapshots_retention_period")) ? (int) $configManager->get("do_snapshots_retention_period") : 7];
        $accessTokenForm = $this->createAccessTokenForm($accessToken);
        $snapshotsSettingsForm = $this->createSnapshotsSettingsForm($snapshotsSettings);
        if (true === $request->isMethod("POST")) {
            $accessTokenForm->handleRequest($request);
            $snapshotsSettingsForm->handleRequest($request);
            if (true === $accessTokenForm->isSubmitted()) {
                $response = $this->handleAccessTokenForm($request, $accessTokenForm, $configManager);
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
        $response = $this->render("Admin/Do/Settings/index.html.twig", ["accessTokenForm" => $accessTokenForm->createView(), "snapshotsSettingsForm" => $snapshotsSettingsForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createAccessTokenForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminDoAccessTokenType", $data, ["action" => $this->generateUrl("clp_admin_do_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleAccessTokenForm(Request $request, Form $form, ConfigManager $configManager)
    {
        $token = $form->get("accessToken")->getData();
        $isAccessTokenValid = $this->validateAccessToken($request, $token);
        if (false === $isAccessTokenValid) {
            $form->addError(new FormError($this->translator->trans("Access Token is not valid.")));
        }
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $configManager->set("do_access_token", $token);
                $eventData = ["accessToken" => sprintf("%s****", substr($token, 0, -4))];
                EventQueue::addEvent(EventQueue::EVENT_DO_ACCESS_TOKEN_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Access Token has been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_do_settings"));
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
        $form = $this->createForm("App\\Form\\AdminDoSnapshotsSettingsType", $data, ["action" => $this->generateUrl("clp_admin_do_settings"), "method" => "POST", "attr" => []]);
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
                $configManager->set("do_automatic_snapshots", $automaticSnapshots);
                $configManager->set("do_snapshots_frequency", $frequency);
                $configManager->set("do_snapshots_retention_period", $retentionPeriod);
                $eventData = ["automaticSnapshots" => $automaticSnapshots, "frequency" => $frequency, "retentionPeriod" => $retentionPeriod];
                EventQueue::addEvent(EventQueue::EVENT_DO_SNAPSHOTS_SETTINGS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshots Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_do_settings"));
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
        if (CloudPanelEnvironment::CLOUD_PROVIDER_DO != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $response = $this->render("Admin/Do/Snapshots/index.html.twig", ["formErrors" => $this->formErrors]);
        return $response;
    }
    public function loadSnapshots(Request $request, ConfigManager $configManager, Twig $twig) : Response
    {
        $data = [];
        if (true === $request->isXmlHttpRequest()) {
            try {
                $user = $this->getUser();
                $snapshots = [];
                $accessToken = $configManager->get("do_access_token");
                if (false === empty($accessToken)) {
                    $instance = $request->attributes->get("instance");
                    $doClient = $instance->getDoClient();
                    $snapshots = $doClient->getSnapshotsForDroplet();
                    usort($snapshots, function ($a, $b) {
                        return $a->getCreatedAt() < $b->getCreatedAt();
                    });
                }
                $snapshotsHtml = $twig->render("Admin/Do/Snapshots/snapshots-list.html.twig", ["user" => $user, "snapshots" => $snapshots]);
                $data["snapshotsHtml"] = $snapshotsHtml;
            } catch (\Exception $e) {
                $errorMessage = $twig->render("Admin/Do/Partial/error-message.html.twig", ["errorMessage" => $e->getMessage()]);
                $data["errorMessage"] = $errorMessage;
            }
        }
        return $this->json($data);
    }
    public function createSnapshot(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_DO != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $data = [];
        $instance = $request->attributes->get("instance");
        if (false === $request->isMethod("POST")) {
            try {
                $session = $request->getSession();
                $doClient = $instance->getDoClient();
                $droplet = $doClient->getDroplet();
                $dropletId = $droplet->getId();
                $dateTime = new \DateTime();
                $dropletName = $droplet->getName();
                if (false === empty($dropletName)) {
                    $data["name"] = sprintf("%s-%s", $dropletName, $dateTime->getTimestamp());
                } else {
                    $data["name"] = sprintf("%s-%s", $dropletId, $dateTime->getTimestamp());
                }
            } catch (\Exception $e) {
                if (401 == $e->getCode()) {
                    $session->getFlashBag()->set("danger", $this->translator->trans("Access Token is not valid."));
                    $response = $this->redirect($this->generateUrl("clp_admin_do_settings"));
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
        $response = $this->render("Admin/Do/Snapshots/create.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createSnapshotForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminDoSnapshotType", $data, ["action" => $this->generateUrl("clp_admin_do_snapshot_create"), "method" => "POST", "attr" => []]);
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
                $doClient = $instance->getDoClient();
                $droplet = $doClient->getDroplet();
                $snapshotName = $form->get("name")->getData();
                $doClient->createDropletSnapshot($snapshotName);
                $dropletVolumeIds = $droplet->getVolumeIds();
                if (false === empty($dropletVolumeIds)) {
                    foreach ($dropletVolumeIds as $dropletVolumeId) {
                        $doClient->createVolumeSnapshot($dropletVolumeId);
                    }
                }
                $eventData = ["snapshotName" => $snapshotName];
                EventQueue::addEvent(EventQueue::EVENT_DO_SNAPSHOT_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being created."));
                $response = $this->redirect($this->generateUrl("clp_admin_do_snapshots"));
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
        $this->checkCsrfToken($request, "do-snapshot-delete");
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_DO != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $id = trim($request->get("id"));
        if (false === empty($id)) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $instance = $request->attributes->get("instance");
                $doClient = $instance->getDoClient();
                $snapshot = $doClient->getDropletSnapshot($id);
                if (false === is_null($snapshot)) {
                    $doClient->deleteDropletSnapshot($id);
                    $eventData = ["snapshotId" => $snapshot->getId(), "snapshotName" => $snapshot->getName()];
                    EventQueue::addEvent(EventQueue::EVENT_DO_SNAPSHOT_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Snapshot is being deleted."));
                }
            } catch (\Exception $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_do_snapshots"));
        return $response;
    }
    private function validateAccessToken(Request $request, $token) : bool
    {
        try {
            $isAccessTokenValid = false;
            $instance = $request->attributes->get("instance");
            $doClient = clone $instance->getDoClient();
            $doClient->unsetHttpClient();
            $doClient->setToken($token);
            $dropletData = $doClient->getDroplet();
            if (false === empty($dropletData)) {
                $isAccessTokenValid = true;
            }
        } catch (\Exception $e) {
        }
        return $isAccessTokenValid;
    }
}