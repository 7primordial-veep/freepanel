<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use App\Entity\Manager\ConfigManager;
use App\System\CommandExecutor;
use App\System\Command\RclonePasswordObscureCommand;
use App\Backup\Rclone\AmazonS3ConfigTemplate;
use App\Backup\Rclone\WasabiConfigTemplate;
use App\Backup\Rclone\DropboxConfigTemplate;
use App\Backup\Rclone\GoogleDriveConfigTemplate;
use App\Backup\Rclone\DigitalOceanSpacesConfigTemplate;
use App\Backup\Rclone\SftpConfigTemplate;
use App\Backup\Rclone\ConfigBuilder as RcloneConfigBuilder;
use App\Backup\Rclone;
use App\Backup\StorageProvider;
use App\Event\EventQueue;
use App\Service\Logger;
use App\Entity\Manager\BackupTestResultManager;
class RemoteBackupController extends Controller
{
    private const REMOTE_BACKUP_COMMAND = "/usr/bin/bash -c \"/usr/bin/sudo /usr/bin/clpctlWrapper remote-backup:create\" > /dev/null 2>&1 &";
    private ConfigManager $configManager;
    private ?BackupTestResultManager $backupTestResultManager = null;
    public function __construct(ConfigManager $configManager, TranslatorInterface $translator, Logger $logger, BackupTestResultManager $backupTestResultManager)
    {
        $this->configManager = $configManager;
        $this->backupTestResultManager = $backupTestResultManager;
        parent::__construct($translator, $logger);
    }
    private function getLastBackupTestResult(): ?\App\Entity\BackupTestResult
    {
        return $this->backupTestResultManager ? $this->backupTestResultManager->findLatestOne() : null;
    }
    public function index(Request $request) : Response
    {
        $provider = $this->configManager->get("remote_backup_storage_provider");
        if (false === empty($provider)) {
            $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => $provider]));
            return $response;
        }
        $form = $this->createRemoteBackupStorageProviderForm();
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleRemoteBackupStorageProviderForm($request, $form);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/RemoteBackup/index.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    public function create(Request $request) : Response
    {
        $provider = $this->configManager->get("remote_backup_storage_provider");
        $this->checkCsrfToken($request, "create-backup");
        if (false === empty($provider)) {
            try {
                $session = $request->getSession();
                exec(self::REMOTE_BACKUP_COMMAND);
                $session->getFlashBag()->set("success", $this->translator->trans("The backup will be started in the background shortly."));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_remote_backup"));
        return $response;
    }
    private function createRemoteBackupStorageProviderForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupStorageProviderType", [], ["action" => $this->generateUrl("clp_admin_remote_backup"), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Continue"]);
        return $form;
    }
    private function handleRemoteBackupStorageProviderForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $storageProvider = $form->get("storageProvider")->getData();
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_new", ["provider" => $storageProvider]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function new(Request $request) : Response
    {
        $storageProvider = $request->get("provider");
        $session = $request->getSession();
        switch ($storageProvider) {
            case StorageProvider::AMAZON_S3:
                $form = $this->createRemoteBackupAmazonS3Form();
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupAmazonS3Form($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/amazon-s3.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::WASABI:
                $form = $this->createRemoteBackupWasabiForm();
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupWasabiForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/wasabi.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::GOOGLE_DRIVE:
                $form = $this->createRemoteBackupGoogleDriveForm();
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupGoogleDriveForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/google-drive.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::DIGITAL_OCEAN_SPACES:
                $form = $this->createRemoteBackupDigitalOceanSpacesForm();
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupDigitalOceanSpacesForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/digital-ocean-spaces.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::DROPBOX:
                $form = $this->createRemoteBackupDropboxForm($request);
                $session = $request->getSession();
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupDropboxForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                } else {
                    $session->remove("token");
                    $session->remove("refreshToken");
                }
                $response = $this->render("Admin/RemoteBackup/dropbox.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::SFTP:
                $form = $this->createRemoteBackupSftpForm();
                $authenticationMethod = "password";
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $authenticationMethod = $form->get("authenticationMethod")->getData();
                        $response = $this->handleRemoteBackupSftpForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/sftp.html.twig", ["authenticationMethod" => $authenticationMethod, "form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::CUSTOM_RCLONE:
                $form = $this->createRemoteBackupCustomRcloneForm();
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupCustomRcloneForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/custom-rclone.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
        }
        $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => sprintf("Storage Provider %s is not supported.", $storageProvider)]));
        $response = $this->redirect($this->generateUrl("clp_admin_remote_backup"));
        return $response;
    }
    private function createRemoteBackupAmazonS3Form() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupAmazonS3Type", [], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::AMAZON_S3]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupWasabiForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupWasabiType", [], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::WASABI]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupGoogleDriveForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupGoogleDriveType", [], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::GOOGLE_DRIVE]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupDigitalOceanSpacesForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupDigitalOceanSpacesType", [], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::DIGITAL_OCEAN_SPACES]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupDropboxForm(Request $request) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupDropboxType", ["request" => $request], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::DROPBOX]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupSftpForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupSftpType", [], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::SFTP]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupCustomRcloneForm() : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupCustomRcloneType", [], ["action" => $this->generateUrl("clp_admin_remote_backup_new", ["provider" => StorageProvider::CUSTOM_RCLONE]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleRemoteBackupAmazonS3Form(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $bucket = $form->get("bucket")->getData();
                $region = $form->get("region")->getData();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::AMAZON_S3);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_bucket", $bucket);
                $this->configManager->set("remote_backup_region", $region);
                $this->configManager->set("remote_backup_access_key", $accessKey);
                $this->configManager->set("remote_backup_secret_access_key", $secretAccessKey);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new AmazonS3ConfigTemplate();
                $rcloneConfigTemplate->setRegion($region);
                $rcloneConfigTemplate->setAccessKeyId($accessKey);
                $rcloneConfigTemplate->setSecretAccessKey($secretAccessKey);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::AMAZON_S3, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "bucket" => $bucket, "region" => $region, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::AMAZON_S3]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupWasabiForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $bucket = $form->get("bucket")->getData();
                $region = $form->get("region")->getData();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::WASABI);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_bucket", $bucket);
                $this->configManager->set("remote_backup_region", $region);
                $this->configManager->set("remote_backup_access_key", $accessKey);
                $this->configManager->set("remote_backup_secret_access_key", $secretAccessKey);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new WasabiConfigTemplate();
                $rcloneConfigTemplate->setRegion($region);
                $rcloneConfigTemplate->setAccessKeyId($accessKey);
                $rcloneConfigTemplate->setSecretAccessKey($secretAccessKey);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::WASABI, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "bucket" => $bucket, "region" => $region, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::WASABI]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupGoogleDriveForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $email = $form->get("email")->getData();
                $serviceAccount = trim($form->get("serviceAccount")->getData());
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::GOOGLE_DRIVE);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_email", $email);
                $this->configManager->set("remote_backup_service_account", $serviceAccount);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new GoogleDriveConfigTemplate();
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->writeCredentialsFile(GoogleDriveConfigTemplate::SERVICE_ACCOUNT_FILE, $serviceAccount);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::GOOGLE_DRIVE, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "e-mail" => $email, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::GOOGLE_DRIVE]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupDropboxForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $token = $session->get("token");
                $refreshToken = $session->get("refreshToken");
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::DROPBOX);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $this->configManager->set("remote_backup_refresh_token", $refreshToken);
                $rcloneConfigTemplate = new DropboxConfigTemplate();
                $rcloneConfigTemplate->setToken($token);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::DROPBOX, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::DROPBOX]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupSftpForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $currentUser = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $authenticationMethod = $form->get("authenticationMethod")->getData();
                $host = trim($form->get("host")->getData());
                $user = trim($form->get("user")->getData());
                $password = trim($form->get("password")->getData());
                $keyFile = trim($form->get("keyFile")->getData());
                $port = $form->get("port")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::SFTP);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_authentication_method", $authenticationMethod);
                $this->configManager->set("remote_backup_host", $host);
                $this->configManager->set("remote_backup_user", $user);
                $this->configManager->set("remote_backup_port", $port);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new SftpConfigTemplate();
                $rcloneConfigTemplate->setSetting("host", $host);
                $rcloneConfigTemplate->setSetting("user", $user);
                if ("password" == $authenticationMethod) {
                    $commandExecutor = new CommandExecutor();
                    $rclonePasswordObscureCommand = new RclonePasswordObscureCommand();
                    $rclonePasswordObscureCommand->setPassword($password);
                    $commandExecutor->execute($rclonePasswordObscureCommand);
                    $obscuredPassword = $rclonePasswordObscureCommand->getObscuredPassword();
                    $rcloneConfigTemplate->setSetting("pass", $obscuredPassword);
                    $this->configManager->set("remote_backup_password", $obscuredPassword);
                } else {
                    $rcloneConfigTemplate->setSetting("key_file", $keyFile);
                    $this->configManager->set("remote_backup_key_file", $keyFile);
                }
                $rcloneConfigTemplate->setSetting("port", $port);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::SFTP, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "authenticationMethod" => $authenticationMethod, "host" => $host, "user" => $user, "port" => $port, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $currentUser, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::SFTP]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupDigitalOceanSpacesForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $space = $form->get("space")->getData();
                $spaceEndpoint = $form->get("spaceEndpoint")->getData();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::DIGITAL_OCEAN_SPACES);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_space", $space);
                $this->configManager->set("remote_backup_space_endpoint", $spaceEndpoint);
                $this->configManager->set("remote_backup_access_key", $accessKey);
                $this->configManager->set("remote_backup_secret_access_key", $secretAccessKey);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new DigitalOceanSpacesConfigTemplate();
                $rcloneConfigTemplate->setEndpoint($spaceEndpoint);
                $rcloneConfigTemplate->setAccessKeyId($accessKey);
                $rcloneConfigTemplate->setSecretAccessKey($secretAccessKey);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::DIGITAL_OCEAN_SPACES, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "space" => $space, "endpoint" => $spaceEndpoint, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::DIGITAL_OCEAN_SPACES]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupCustomRcloneForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::CUSTOM_RCLONE);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rclone = new Rclone();
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::CUSTOM_RCLONE, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::CUSTOM_RCLONE]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function edit(Request $request) : Response
    {
        $storageProvider = $request->get("provider");
        $session = $request->getSession();
        $excludes = $this->configManager->get("remote_backup_excludes");
        if (false === empty($excludes)) {
            $excludes = (array) json_decode($excludes);
            $excludes = implode(PHP_EOL, $excludes);
        }
        switch ($storageProvider) {
            case StorageProvider::AMAZON_S3:
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "bucket" => $this->configManager->get("remote_backup_bucket"), "region" => $this->configManager->get("remote_backup_region"), "accessKey" => $this->configManager->get("remote_backup_access_key"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupAmazonS3EditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupAmazonS3EditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/amazon-s3-edit.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::WASABI:
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "bucket" => $this->configManager->get("remote_backup_bucket"), "region" => $this->configManager->get("remote_backup_region"), "accessKey" => $this->configManager->get("remote_backup_access_key"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupWasabiEditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupWasabiEditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/wasabi-edit.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::GOOGLE_DRIVE:
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "email" => $this->configManager->get("remote_backup_email"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupGoogleDriveEditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupGoogleDriveEditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/google-drive-edit.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::DIGITAL_OCEAN_SPACES:
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "space" => $this->configManager->get("remote_backup_space"), "spaceEndpoint" => $this->configManager->get("remote_backup_space_endpoint"), "accessKey" => $this->configManager->get("remote_backup_access_key"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupDigitalOceanSpacesEditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupDigitalOceanSpacesEditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/digital-ocean-spaces-edit.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::DROPBOX:
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupDropboxEditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupDropboxEditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/dropbox-edit.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::SFTP:
                $authenticationMethod = $this->configManager->get("remote_backup_authentication_method");
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "authenticationMethod" => $authenticationMethod, "host" => $this->configManager->get("remote_backup_host"), "user" => $this->configManager->get("remote_backup_user"), "keyFile" => $this->configManager->get("remote_backup_key_file"), "port" => $this->configManager->get("remote_backup_port"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupSftpEditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $authenticationMethod = $form->get("authenticationMethod")->getData();
                        $response = $this->handleRemoteBackupSftpEditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/sftp-edit.html.twig", ["authenticationMethod" => $authenticationMethod, "form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
            case StorageProvider::CUSTOM_RCLONE:
                $data = ["enableRemoteBackup" => (bool) $this->configManager->get("remote_backup_enabled"), "frequency" => $this->configManager->get("remote_backup_frequency"), "executionTime" => $this->configManager->get("remote_backup_execution_time"), "storageDirectory" => $this->configManager->get("remote_backup_storage_directory"), "retentionPeriod" => (int) $this->configManager->get("remote_backup_retention_period"), "excludes" => $excludes];
                $form = $this->createRemoteBackupCustomRcloneEditForm($data);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleRemoteBackupCustomRcloneEditForm($request, $form);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Admin/RemoteBackup/custom-rclone-edit.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
                return $response;
        }
        $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => sprintf("Storage Provider %s is not supported.", $storageProvider)]));
        $response = $this->redirect($this->generateUrl("clp_admin_remote_backup"));
        return $response;
    }
    private function createRemoteBackupAmazonS3EditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupAmazonS3EditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::AMAZON_S3]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupWasabiEditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupWasabiEditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::WASABI]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupGoogleDriveEditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupGoogleDriveEditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::GOOGLE_DRIVE]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupDigitalOceanSpacesEditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupDigitalOceanSpacesEditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::DIGITAL_OCEAN_SPACES]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupDropboxEditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupDropboxEditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::DROPBOX]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupSftpEditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupSftpEditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::SFTP]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createRemoteBackupCustomRcloneEditForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminRemoteBackupCustomRcloneEditType", $data, ["action" => $this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::CUSTOM_RCLONE]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleRemoteBackupAmazonS3EditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $bucket = $form->get("bucket")->getData();
                $region = $form->get("region")->getData();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                if (true === empty($secretAccessKey)) {
                    $secretAccessKey = $this->configManager->get("remote_backup_secret_access_key");
                }
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::AMAZON_S3);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_bucket", $bucket);
                $this->configManager->set("remote_backup_region", $region);
                $this->configManager->set("remote_backup_access_key", $accessKey);
                $this->configManager->set("remote_backup_secret_access_key", $secretAccessKey);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new AmazonS3ConfigTemplate();
                $rcloneConfigTemplate->setRegion($region);
                $rcloneConfigTemplate->setAccessKeyId($accessKey);
                $rcloneConfigTemplate->setSecretAccessKey($secretAccessKey);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::AMAZON_S3, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "bucket" => $bucket, "region" => $region, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_EDIT, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::AMAZON_S3]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupWasabiEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $bucket = $form->get("bucket")->getData();
                $region = $form->get("region")->getData();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                if (true === empty($secretAccessKey)) {
                    $secretAccessKey = $this->configManager->get("remote_backup_secret_access_key");
                }
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::WASABI);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_bucket", $bucket);
                $this->configManager->set("remote_backup_region", $region);
                $this->configManager->set("remote_backup_access_key", $accessKey);
                $this->configManager->set("remote_backup_secret_access_key", $secretAccessKey);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new WasabiConfigTemplate();
                $rcloneConfigTemplate->setRegion($region);
                $rcloneConfigTemplate->setAccessKeyId($accessKey);
                $rcloneConfigTemplate->setSecretAccessKey($secretAccessKey);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::WASABI, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "bucket" => $bucket, "region" => $region, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_EDIT, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::WASABI]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupGoogleDriveEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $email = $form->get("email")->getData();
                $serviceAccount = $form->get("serviceAccount")->getData();
                if (true === empty($serviceAccount)) {
                    $serviceAccount = $this->configManager->get("remote_backup_service_account");
                }
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::GOOGLE_DRIVE);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_email", $email);
                $this->configManager->set("remote_backup_service_account", trim($serviceAccount));
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new GoogleDriveConfigTemplate();
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->writeCredentialsFile(GoogleDriveConfigTemplate::SERVICE_ACCOUNT_FILE, $serviceAccount);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::GOOGLE_DRIVE, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "e-amil" => $email, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_EDIT, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::GOOGLE_DRIVE]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupDropboxEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $refreshToken = $this->configManager->get("remote_backup_refresh_token");
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::DROPBOX);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $this->configManager->set("remote_backup_refresh_token", $refreshToken);
                $rclone = new Rclone();
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::DROPBOX, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_EDIT, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::DROPBOX]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupSftpEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $currentUser = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $authenticationMethod = $form->get("authenticationMethod")->getData();
                $host = trim($form->get("host")->getData());
                $user = trim($form->get("user")->getData());
                $password = trim($form->get("password")->getData());
                $obscuredPassword = $this->configManager->get("remote_backup_password");
                $keyFile = trim($form->get("keyFile")->getData());
                $port = $form->get("port")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::SFTP);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_authentication_method", $authenticationMethod);
                $this->configManager->set("remote_backup_host", $host);
                $this->configManager->set("remote_backup_user", $user);
                $this->configManager->set("remote_backup_port", $port);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new SftpConfigTemplate();
                $rcloneConfigTemplate->setSetting("host", $host);
                $rcloneConfigTemplate->setSetting("user", $user);
                if ("password" == $authenticationMethod) {
                    if (false === empty($password)) {
                        $commandExecutor = new CommandExecutor();
                        $rclonePasswordObscureCommand = new RclonePasswordObscureCommand();
                        $rclonePasswordObscureCommand->setPassword($password);
                        $commandExecutor->execute($rclonePasswordObscureCommand);
                        $obscuredPassword = $rclonePasswordObscureCommand->getObscuredPassword();
                    }
                    $rcloneConfigTemplate->setSetting("pass", $obscuredPassword);
                    $this->configManager->set("remote_backup_password", $obscuredPassword);
                } else {
                    $rcloneConfigTemplate->setSetting("key_file", $keyFile);
                    $this->configManager->set("remote_backup_key_file", $keyFile);
                }
                $rcloneConfigTemplate->setSetting("port", $port);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::SFTP, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "authenticationMethod" => $authenticationMethod, "host" => $host, "user" => $user, "port" => $port, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_CREATE, $currentUser, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::SFTP]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupDigitalOceanSpacesEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $space = $form->get("space")->getData();
                $spaceEndpoint = $form->get("spaceEndpoint")->getData();
                $accessKey = $form->get("accessKey")->getData();
                $secretAccessKey = $form->get("secretAccessKey")->getData();
                if (true === empty($secretAccessKey)) {
                    $secretAccessKey = $this->configManager->get("remote_backup_secret_access_key");
                }
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::DIGITAL_OCEAN_SPACES);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_space", $space);
                $this->configManager->set("remote_backup_space_endpoint", $spaceEndpoint);
                $this->configManager->set("remote_backup_access_key", $accessKey);
                $this->configManager->set("remote_backup_secret_access_key", $secretAccessKey);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rcloneConfigTemplate = new DigitalOceanSpacesConfigTemplate();
                $rcloneConfigTemplate->setEndpoint($spaceEndpoint);
                $rcloneConfigTemplate->setAccessKeyId($accessKey);
                $rcloneConfigTemplate->setSecretAccessKey($secretAccessKey);
                $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
                $rcloneConfig = $rcloneConfigBuilder->build();
                $rclone = new Rclone();
                $rclone->writeConfig($rcloneConfig);
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::DIGITAL_OCEAN_SPACES, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "space" => $space, "spaceEndpoint" => $spaceEndpoint, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_EDIT, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::DIGITAL_OCEAN_SPACES]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleRemoteBackupCustomRcloneEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $user = $this->getUser();
                $remoteBackupEnabled = (int) $form->get("enableRemoteBackup")->getData();
                $frequency = $form->get("frequency")->getData();
                $executionTime = $form->get("executionTime")->getData();
                $retentionPeriod = (int) $form->get("retentionPeriod")->getData();
                $storageDirectory = trim($form->get("storageDirectory")->getData());
                $excludes = $form->get("excludes")->getData();
                $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($excludes))));
                $excludes = false === empty($excludes) ? json_encode($excludes) : '';
                $this->configManager->deleteByWildcard("remote_backup_%");
                $this->configManager->set("remote_backup_storage_provider", StorageProvider::CUSTOM_RCLONE);
                $this->configManager->set("remote_backup_enabled", $remoteBackupEnabled);
                $this->configManager->set("remote_backup_frequency", $frequency);
                $this->configManager->set("remote_backup_execution_time", $executionTime);
                $this->configManager->set("remote_backup_retention_period", $retentionPeriod);
                $this->configManager->set("remote_backup_storage_directory", $storageDirectory);
                $this->configManager->set("remote_backup_excludes", $excludes);
                $rclone = new Rclone();
                $rclone->createCronJob($frequency, $executionTime);
                $eventData = ["storageProvider" => StorageProvider::CUSTOM_RCLONE, "remoteBackupEnabled" => $remoteBackupEnabled, "frequency" => $frequency, "executionTime" => $executionTime, "storageDirectory" => $storageDirectory];
                EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_EDIT, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been saved."));
                $response = $this->redirect($this->generateUrl("clp_admin_remote_backup_edit", ["provider" => StorageProvider::CUSTOM_RCLONE]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function delete(Request $request) : Response
    {
        try {
            $session = $request->getSession();
            $user = $this->getUser();
            $this->checkCsrfToken($request, "remote-backup-delete");
            $this->configManager->deleteByWildcard("remote_backup_%");
            $rclone = new Rclone();
            $rclone->deleteCronJob();
            $rclone->deleteCredentials();
            EventQueue::addEvent(EventQueue::EVENT_REMOTE_BACKUP_DELETE, $user, [], $request);
            $session->getFlashBag()->set("success", $this->translator->trans("Remote Backup Settings have been deleted."));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        $response = $this->redirect($this->generateUrl("clp_admin_remote_backup"));
        return $response;
    }
}