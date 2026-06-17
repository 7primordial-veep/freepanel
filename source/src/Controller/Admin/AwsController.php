<?php

namespace App\Controller\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use Twig\Environment as Twig;
use Aws\Credentials\Credentials as AwsCredentials;
use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;
use App\Controller\Controller;
use App\Aws\Ami as AwsAmi;
use App\Aws\Instance as AwsInstance;
use App\Entity\Manager\ConfigManager;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Event\EventQueue;
class AwsController extends Controller
{
    public function settings(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_AWS != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $accessKeys = ["accessKey" => $configManager->get("aws_access_key"), "secretAccessKey" => true === empty($configManager->get("aws_secret_access_key")) ? '' : sprintf("%s********************", substr($configManager->get("aws_secret_access_key"), 0, -20))];
        $imagesSettings = ["automaticImages" => (bool) $configManager->get("aws_automatic_images"), "frequency" => false === is_null($configManager->get("aws_images_frequency")) ? (int) $configManager->get("aws_images_frequency") : 12, "retentionPeriod" => false === is_null($configManager->get("aws_images_retention_period")) ? (int) $configManager->get("aws_images_retention_period") : 7];
        $accessKeysForm = $this->createAccessKeysForm($accessKeys);
        $imagesSettingsForm = $this->createImagesSettingsForm($imagesSettings);
        if (true === $request->isMethod("POST")) {
            $accessKeysForm->handleRequest($request);
            $imagesSettingsForm->handleRequest($request);
            if (true === $accessKeysForm->isSubmitted()) {
                $response = $this->handleAccessKeysForm($request, $accessKeysForm, $configManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
            if (true === $imagesSettingsForm->isSubmitted()) {
                $response = $this->handleAwsImagesSettingsForm($request, $imagesSettingsForm, $configManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Aws/Settings/index.html.twig", ["accessKeysForm" => $accessKeysForm->createView(), "imagesSettingsForm" => $imagesSettingsForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createAccessKeysForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminAwsAccessKeysType", $data, ["action" => $this->generateUrl("clp_admin_aws_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createImagesSettingsForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminAwsImagesSettingsType", $data, ["action" => $this->generateUrl("clp_admin_aws_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleAccessKeysForm(Request $request, Form $form, ConfigManager $configManager)
    {
        $validAccessKeys = $this->validateAccessKeys($request, $form);
        if (false === $validAccessKeys) {
            $form->addError(new FormError($this->translator->trans("AWS Access Keys are not valid.")));
        }
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                $configManager->set("aws_access_key", $accessKey);
                $configManager->set("aws_secret_access_key", $secretAccessKey);
                $eventData = ["accessKey" => sprintf("%s****", substr($accessKey, 0, -4)), "secretAccessKey" => sprintf("%s****", substr($secretAccessKey, 0, -4))];
                EventQueue::addEvent(EventQueue::EVENT_AWS_ACCESS_KEYS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("AWS Access Keys have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_aws_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleAwsImagesSettingsForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $automaticImages = (int) $form->get("automaticImages")->getData();
                $frequency = (int) $form->get("frequency")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $configManager->set("aws_automatic_images", $automaticImages);
                $configManager->set("aws_images_frequency", $frequency);
                $configManager->set("aws_images_retention_period", $retentionPeriod);
                $eventData = ["automaticImages" => $automaticImages, "frequency" => $frequency, "retentionPeriod" => $retentionPeriod];
                EventQueue::addEvent(EventQueue::EVENT_AWS_IMAGES_SETTINGS_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Images Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_aws_settings"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function images(Request $request) : Response
    {
        $response = $this->render("Admin/Aws/Images/index.html.twig", ["formErrors" => $this->formErrors]);
        return $response;
    }
    public function createImage(Request $request, ConfigManager $configManager) : Response
    {
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_AWS != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $data = [];
        $instance = $request->attributes->get("instance");
        $instanceId = $instance->getInstanceId();
        if (false === $request->isMethod("POST")) {
            try {
                $session = $request->getSession();
                $region = $instance->getRegion();
                $accessKey = $configManager->get("aws_access_key");
                $secretAccessKey = $configManager->get("aws_secret_access_key");
                $ec2Client = $this->getEc2Client($region, $accessKey, $secretAccessKey);
                $result = $ec2Client->describeInstances(["InstanceIds" => [$instanceId]]);
                $instances = $result["Reservations"][0]["Instances"] ?? [];
                $instance = $instances[0] ?? [];
                if (false == empty($instance)) {
                    $awsInstance = new AwsInstance();
                    $tags = (array) $instance["Tags"] ?? [];
                    $awsInstance->setTags($tags);
                    $instanceName = $awsInstance->getInstanceName();
                    $dateTime = new \DateTime();
                    if (false === empty($instanceName)) {
                        $data["name"] = sprintf("%s_clp_%s", strtolower($instanceName), $dateTime->getTimestamp());
                    } else {
                        $data["name"] = sprintf("%s_clp_%s", $instanceId, $dateTime->getTimestamp());
                    }
                }
            } catch (\Exception $e) {
                if ($e instanceof Ec2Exception) {
                    $session->getFlashBag()->set("danger", $this->translator->trans("AWS Access Keys are not valid."));
                    $response = $this->redirect($this->generateUrl("clp_admin_aws_settings"));
                    return $response;
                }
            }
        }
        $form = $this->createImageForm($data);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleCreateImageForm($request, $form, $configManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Aws/Images/create.html.twig", ["formErrors" => $this->formErrors, "form" => $form->createView()]);
        return $response;
    }
    private function createImageForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminAwsImageType", $data, ["action" => $this->generateUrl("clp_admin_aws_image_create"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Create"]);
        return $form;
    }
    private function handleCreateImageForm(Request $request, Form $form, ConfigManager $configManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $imageName = $form->get("name")->getData();
                $instance = $request->attributes->get("instance");
                $instanceId = $instance->getInstanceId();
                $region = $instance->getRegion();
                $accessKey = $configManager->get("aws_access_key");
                $secretAccessKey = $configManager->get("aws_secret_access_key");
                $instanceUid = $configManager->get("instance_uid");
                $ec2Client = $this->getEc2Client($region, $accessKey, $secretAccessKey);
                $imageConfiguration = ["InstanceId" => $instanceId, "Name" => $imageName, "NoReboot" => true];
                $imageId = $this->retry(function () use($ec2Client, $imageConfiguration) {
                    $result = $ec2Client->createImage($imageConfiguration);
                    $imageId = $result["ImageId"] ?? '';
                    return $imageId;
                });
                $this->retry(function () use($instanceUid, $imageId, $ec2Client) {
                    $ec2Client->createTags(["Resources" => [$imageId], "Tags" => [["Key" => "InstanceUid", "Value" => $instanceUid], ["Key" => "Type", "Value" => AwsAmi::TYPE_MANUAL], ["Key" => "CreatedBy", "Value" => "CloudPanel"]]]);
                });
                $eventData = ["imageId" => $imageId, "imageName" => $imageName];
                EventQueue::addEvent(EventQueue::EVENT_AWS_IMAGE_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Image is being created."));
                $response = $this->redirect($this->generateUrl("clp_admin_aws_images"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function loadImages(Request $request, ConfigManager $configManager, Twig $twig) : Response
    {
        $data = [];
        if (true === $request->isXmlHttpRequest()) {
            try {
                $user = $this->getUser();
                $images = [];
                $instance = $request->attributes->get("instance");
                $region = $instance->getRegion();
                $accessKey = $configManager->get("aws_access_key");
                $secretAccessKey = $configManager->get("aws_secret_access_key");
                if (false === empty($accessKey)) {
                    $instanceUid = $configManager->get("instance_uid");
                    $ec2Client = $this->getEc2Client($region, $accessKey, $secretAccessKey);
                    $tagFilter = [["Name" => "tag:InstanceUid", "Values" => [$instanceUid]]];
                    $result = $ec2Client->describeImages(["Filters" => $tagFilter]);
                    $awsImages = (array) $result->get("Images");
                    if (false === empty($awsImages)) {
                        foreach ($awsImages as $awsImage) {
                            $imageId = $awsImage["ImageId"] ?? '';
                            $state = $awsImage["State"] ?? '';
                            $createdAt = true === isset($awsImage["CreationDate"]) ? new \DateTime($awsImage["CreationDate"]) : new \DateTime();
                            $createdAt->setTimezone(new \DateTimeZone("UTC"));
                            $description = $awsImage["Description"] ?? '';
                            $name = $awsImage["Name"] ?? '';
                            $tags = true === isset($awsImage["Tags"]) && true === is_array($awsImage["Tags"]) ? $awsImage["Tags"] : [];
                            $awsAmi = new AwsAmi();
                            $awsAmi->setAmiId($imageId);
                            $awsAmi->setCreatedAt($createdAt);
                            $awsAmi->setName($name);
                            $awsAmi->setDescription($description);
                            $awsAmi->setState($state);
                            $awsAmi->setTags($tags);
                            $images[] = $awsAmi;
                        }
                    }
                    usort($images, function ($a, $b) {
                        return $a->getCreatedAt() < $b->getCreatedAt();
                    });
                }
                $imagesHtml = $twig->render("Admin/Aws/Images/images-list.html.twig", ["user" => $user, "images" => $images]);
                $data["imagesHtml"] = $imagesHtml;
            } catch (\Exception $e) {
                $errorMessage = $twig->render("Admin/Aws/Partial/error-message.html.twig", ["errorMessage" => $e->getMessage()]);
                $data["errorMessage"] = $errorMessage;
            }
        }
        return $this->json($data);
    }
    public function deleteImage(Request $request, ConfigManager $configManager) : Response
    {
        $this->checkCsrfToken($request, "aws-image-delete");
        $cloud = $configManager->get("cloud");
        if (CloudPanelEnvironment::CLOUD_PROVIDER_AWS != $cloud) {
            return $this->redirect($this->generateUrl("clp_admin_users"));
        }
        $imageId = trim($request->get("id"));
        if (false === empty($imageId)) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $instance = $request->attributes->get("instance");
                $region = $instance->getRegion();
                $accessKey = $configManager->get("aws_access_key");
                $secretAccessKey = $configManager->get("aws_secret_access_key");
                $ec2Client = $this->getEc2Client($region, $accessKey, $secretAccessKey);
                $result = $ec2Client->describeImages(["Filters" => [["Name" => "image-id", "Values" => [$imageId]]]]);
                $images = (array) $result->get("Images");
                $image = $images[0] ?? [];
                if (!(false === empty($image))) {
                    throw new \Exception(sprintf("AMI \"%s\" does not exist", $imageId));
                }
                $imageName = $image["Name"] ?? '';
                $this->retry(function () use($ec2Client, $imageId) {
                    $ec2Client->deregisterImage(["ImageId" => $imageId]);
                });
                $blockDeviceMappings = $image["BlockDeviceMappings"] ?? [];
                if (false == empty($blockDeviceMappings) && true === is_array($blockDeviceMappings)) {
                    foreach ($blockDeviceMappings as $blockDevice) {
                        $snapshotId = $blockDevice["Ebs"]["SnapshotId"] ?? null;
                        if (!(false === is_null($snapshotId))) {
                            continue;
                        }
                        $this->retry(function () use($ec2Client, $snapshotId) {
                            $ec2Client->deleteSnapshot(["SnapshotId" => $snapshotId]);
                        });
                    }
                }
                $eventData = ["imageId" => $imageId, "imageName" => $imageName];
                EventQueue::addEvent(EventQueue::EVENT_AWS_IMAGE_DELETE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Image is being deleted."));
            } catch (\Exception $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_aws_images"));
        return $response;
    }
    private function validateAccessKeys(Request $request, Form $form) : bool
    {
        try {
            $accessKeysValid = false;
            $instance = $request->attributes->get("instance");
            $accessKey = $form->get("accessKey")->getData();
            $secretAccessKey = $form->get("secretAccessKey")->getData();
            $credentials = new AwsCredentials($accessKey, $secretAccessKey);
            $region = $instance->getRegion();
            $ec2Client = new Ec2Client(["version" => "latest", "region" => $region, "credentials" => $credentials]);
            $result = $ec2Client->describeSecurityGroups();
            $accessKeysValid = true;
        } catch (\Exception $e) {
            $accessKeysValid = false;
        }
        return $accessKeysValid;
    }
    private function getEc2Client($region, $accessKey, $secretAccessKey) : Ec2Client
    {
        $credentials = new AwsCredentials($accessKey, $secretAccessKey);
        $ec2Client = new Ec2Client(["version" => "latest", "region" => $region, "credentials" => $credentials]);
        return $ec2Client;
    }
}