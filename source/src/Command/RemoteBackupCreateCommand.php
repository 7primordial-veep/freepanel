<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Command\Command as BaseCommand;
use App\System\CommandExecutor;
use App\System\Command\WriteFileCommand;
use App\System\Command\DeleteFileCommand;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Site as SiteEntity;
use App\Entity\Notification;
use App\Notification\NotificationQueue;
use App\Backup\Rclone;
use App\Backup\Dropbox\Client as DropboxClient;
use App\Backup\Rclone\DropboxConfigTemplate;
use App\Backup\Rclone\ConfigBuilder as RcloneConfigBuilder;
use App\Backup\StorageProvider;
use App\Service\Logger;
class RemoteBackupCreateCommand extends BaseCommand
{
    private SiteEntityManager $siteEntityManager;
    private CommandExecutor $commandExecutor;
    private ValidatorInterface $validator;
    private ?\DateTime $serverDateTime = null;
    private array $excludes = [];
    private ?string $storageProvider = null;
    public function __construct(SiteEntityManager $siteEntityManager, Logger $logger, ValidatorInterface $validator)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->commandExecutor = new CommandExecutor();
        $this->logger = $logger;
        $this->validator = $validator;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("remote-backup:create");
        $this->setDescription("clpctl remote-backup:create");
        $this->addOption("delay", null, InputOption::VALUE_OPTIONAL, false);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $isEnabled = (bool) $this->getConfigValue("remote_backup_enabled");
            $this->storageProvider = $this->getConfigValue("remote_backup_storage_provider");
            if (true === $isEnabled && false === empty($this->storageProvider)) {
                $siteEntities = $this->siteEntityManager->findAll();
                if (count($siteEntities)) {
                    $this->backupDatabases(clone $input, clone $output);
                    if (StorageProvider::DROPBOX == $this->storageProvider) {
                        $delay = (bool) $input->getOption("delay");
                        $this->refreshDropboxAccessToken($delay);
                    }
                    foreach ($siteEntities as $siteEntity) {
                        $this->backupSite($siteEntity);
                    }
                }
                $this->cleanBackups();
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function backupDatabases(InputInterface $input, OutputInterface $output) : void
    {
        try {
            $application = $this->getApplication();
            $command = $application->find("db:backup");
            $exitCode = $command->run($input, $output);
        } catch (\Exception $e) {
            $this->logger->exception($e);
        }
    }
    private function backupSite(SiteEntity $siteEntity)
    {
        $isSiteExcluded = $this->isSiteExcluded($siteEntity);
        if (true === $isSiteExcluded) {
            return;
        }
        $excludes = [".ssh", "tmp", "logs"];
        $siteExcludes = $this->getSiteExcludes($siteEntity);
        if (false === empty($siteExcludes)) {
            $excludes = array_merge($excludes, $siteExcludes);
        }
        $siteUser = $siteEntity->getUser();
        $domainName = $siteEntity->getDomainName();
        $homeDirectory = sprintf("/home/%s/", $siteUser);
        $vhostFile = sprintf("/etc/nginx/sites-enabled/%s.conf", $domainName);
        $siteSettingsFile = sprintf("/home/%s/site-settings.json", $siteUser);
        $siteVhostFile = sprintf("/home/%s/site-vhost", $siteUser);
        $destinationDirectory = $this->getDestination($siteEntity);
        $destinationObject = $destinationDirectory . "backup.tar.gz";

        $rclone = new Rclone();
        if (StorageProvider::GOOGLE_DRIVE == $this->storageProvider) {
            $email = $this->getConfigValue("remote_backup_email");
            $rclone->addFlag("--drive-impersonate", $email);
        }

        try {
            try {
                $this->createSiteSettingsFile($siteSettingsFile, $siteEntity);
                $this->createSiteVhostFile($siteVhostFile, $siteEntity);
                $sources = [$homeDirectory, $vhostFile];
                $rclone->streamUpload($sources, $excludes, $destinationObject);
            } catch (\Exception $e) {
                try {
                    $rclone->deleteFile($destinationObject);
                } catch (\Exception $cleanupException) {
                    $this->logger->exception($cleanupException);
                }
                throw $e;
            } finally {
                foreach ([$siteSettingsFile, $siteVhostFile] as $file) {
                    $deleteFileCommand = new DeleteFileCommand();
                    $deleteFileCommand->setFile($file);
                    $this->commandExecutor->execute($deleteFileCommand, 60);
                }
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $subject = sprintf("Remote Backup failed: %s", $domainName);
            $this->addNotification($subject, $errorMessage);
        }
    }
    private function createSiteSettingsFile(string $siteSettingsFile, SiteEntity $siteEntity) : void
    {
        $siteType = $siteEntity->getType();
        $settings = ["type" => $siteType, "domainName" => $siteEntity->getDomainName(), "rootDirectory" => $siteEntity->getRootDirectory(), "siteUser" => $siteEntity->getUser(), "pageSpeed" => $siteEntity->getPageSpeedSettings()];
        switch ($siteType) {
            case SiteEntity::TYPE_NODEJS:
                $nodejsSettingsEntity = $siteEntity->getNodejsSettings();
                $nodejsSettings = ["version" => $nodejsSettingsEntity->getNodejsVersion(), "port" => $nodejsSettingsEntity->getPort()];
                $settings["nodejsSettings"] = $nodejsSettings;
                break;
            case SiteEntity::TYPE_PHP:
                $phpSettingsEntity = $siteEntity->getPhpSettings();
                $phpSettings = ["version" => $phpSettingsEntity->getPhpVersion(), "memoryLimit" => $phpSettingsEntity->getMemoryLimit(), "maxExecutionTime" => $phpSettingsEntity->getMaxExecutionTime(), "maxInputTime" => $phpSettingsEntity->getMaxInputTime(), "maxInputVars" => $phpSettingsEntity->getMaxInputVars(), "postMaxSize" => $phpSettingsEntity->getPostMaxSize(), "uploadMaxFileSize" => $phpSettingsEntity->getUploadMaxFileSize(), "additionalConfiguration" => $phpSettingsEntity->getAdditionalConfiguration()];
                $settings["phpSettings"] = $phpSettings;
                break;
            case SiteEntity::TYPE_PYTHON:
                $pythonSettingsEntity = $siteEntity->getPythonSettings();
                $pythonSettings = ["version" => $pythonSettingsEntity->getPythonVersion(), "port" => $pythonSettingsEntity->getPort()];
                $settings["pythonSettings"] = $pythonSettings;
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $reverseProxySettings = ["url" => $siteEntity->getReverseProxyUrl()];
                $settings["reverseProxySettings"] = $reverseProxySettings;
                break;
        }
        $fileContent = json_encode($settings, JSON_PRETTY_PRINT);
        $writeFileCommand = new WriteFileCommand();
        $writeFileCommand->setFile($siteSettingsFile);
        $writeFileCommand->setContent($fileContent);
        $this->commandExecutor->execute($writeFileCommand, 15);
    }
    private function createSiteVhostFile(string $siteVhostFile, SiteEntity $siteEntity) : void
    {
        $vhost = $siteEntity->getVhostTemplate();
        $writeFileCommand = new WriteFileCommand();
        $writeFileCommand->setFile($siteVhostFile);
        $writeFileCommand->setContent($vhost);
        $this->commandExecutor->execute($writeFileCommand, 15);
    }
    private function isSiteExcluded(SiteEntity $siteEntity) : bool
    {
        $isSiteExcluded = false;
        $excludes = $this->getExcludes();
        if (false === empty($excludes)) {
            $siteUser = $siteEntity->getUser();
            $homeDirectory = sprintf("/home/%s", $siteUser);
            foreach ($excludes as $exclude) {
                $exclude = rtrim($exclude, "/");
                if (!($homeDirectory == $exclude)) {
                    continue;
                }
                $isSiteExcluded = true;
                break;
            }
        }
        return $isSiteExcluded;
    }
    private function getExcludes() : array
    {
        if (true === empty($this->excludes)) {
            $excludes = $this->getConfigValue("remote_backup_excludes");
            if (false === empty($excludes)) {
                $decoded = json_decode($excludes, true);
                $this->excludes = is_array($decoded) ? $decoded : [];
            }
        }
        return $this->excludes;
    }
    private function getSiteExcludes(SiteEntity $siteEntity) : array
    {
        $siteExcludes = [];
        $excludes = $this->getExcludes();
        if (false === empty($excludes)) {
            $siteUser = $siteEntity->getUser();
            $homeDirectory = sprintf("/home/%s/", $siteUser);
            foreach ($excludes as $exclude) {
                if (!($homeDirectory == substr($exclude, 0, strlen($homeDirectory)))) {
                    continue;
                }
                $exclude = substr($exclude, strlen($homeDirectory) - 1);
                if (!(false === empty($exclude))) {
                    continue;
                }
                $siteExcludes[] = rtrim(ltrim($exclude, "/"), "/");
            }
        }
        return $siteExcludes;
    }
    private function getServerDateTime() : \DateTime
    {
        if (true === is_null($this->serverDateTime)) {
            $timezone = $this->getConfigValue("timezone");
            $this->serverDateTime = new \DateTime("now");
            $this->serverDateTime->setTimezone(new \DateTimeZone("UTC"));
            if (false === empty($timezone)) {
                $this->serverDateTime->setTimezone(new \DateTimeZone($timezone));
            }
        }
        return $this->serverDateTime;
    }
    private function getDestination(SiteEntity $siteEntity) : string
    {
        $destination = '';
        switch ($this->storageProvider) {
            case StorageProvider::AMAZON_S3:
            case StorageProvider::WASABI:
                $destination = $this->getConfigValue("remote_backup_bucket");
                break;
            case StorageProvider::DIGITAL_OCEAN_SPACES:
                $destination = $this->getConfigValue("remote_backup_space");
                break;
        }
        $siteUser = $siteEntity->getUser();
        $storageDirectory = $this->getConfigValue("remote_backup_storage_directory");
        $serverDateTime = $this->getServerDateTime();
        $destination = sprintf("%s/%s/%s/%s/home/%s/", $destination, rtrim(ltrim($storageDirectory, "/"), "/"), $serverDateTime->format("Y-m-d"), $serverDateTime->format("H.i"), $siteUser);
        if (StorageProvider::DROPBOX == $this->storageProvider) {
            $destination = ltrim($destination, "/");
        }
        return $destination;
    }
    private function cleanBackups() : void
    {
        $retentionPeriod = (int) $this->getConfigValue("remote_backup_retention_period");
        $storageDirectory = $this->getConfigValue("remote_backup_storage_directory");
        if ($retentionPeriod && false === empty($storageDirectory)) {
            try {
                $dateTime = new \DateTime();
                $deleteDateTime = clone $dateTime;
                $deleteDateTime->modify(sprintf("-%s days", $retentionPeriod));
                $deleteDateTime->setTimezone(new \DateTimeZone("UTC"));
                $deleteDateTime->setTime(0, 0, 0, 0);
                $remotePath = '';
                $rclone = new Rclone();
                switch ($this->storageProvider) {
                    case StorageProvider::AMAZON_S3:
                    case StorageProvider::WASABI:
                        $remotePath = $this->getConfigValue("remote_backup_bucket");
                        break;
                    case StorageProvider::DIGITAL_OCEAN_SPACES:
                        $remotePath = $this->getConfigValue("remote_backup_space");
                        break;
                    case StorageProvider::GOOGLE_DRIVE:
                        $email = $this->getConfigValue("remote_backup_email");
                        $rclone->addFlag("--drive-impersonate", $email);
                        break;
                }
                $remotePath = sprintf("%s/%s/", $remotePath, rtrim(ltrim($storageDirectory, "/"), "/"));
                if (StorageProvider::DROPBOX == $this->storageProvider) {
                    $remotePath = ltrim($remotePath, "/");
                }
                $directories = (array) $rclone->lsJson($remotePath, true);
                if (false === empty($directories)) {
                    foreach ($directories as $directory) {
                        $directoryName = $directory["Name"] ?? null;
                        if (!(false === is_null($directoryName))) {
                            continue;
                        }
                        try {
                            $isValidDate = true;
                            $directoryDate = new \DateTime($directoryName);
                        } catch (\Exception $e) {
                            $isValidDate = false;
                        }
                        if (!(true === $isValidDate)) {
                            continue;
                        }
                        if (!(true === isset($directoryDate) && $directoryDate <= $deleteDateTime)) {
                            continue;
                        }
                        $purgeRemotePath = sprintf("%s/%s/", rtrim(ltrim($remotePath, "/"), "/"), $directoryName);
                        if (StorageProvider::DROPBOX == $this->storageProvider) {
                            $purgeRemotePath = ltrim($purgeRemotePath, "/");
                        }
                        if (StorageProvider::SFTP == $this->storageProvider) {
                            $purgeRemotePath = sprintf("/%s", ltrim($purgeRemotePath, "/"));
                        }
                        $rclone->purge($purgeRemotePath);
                    }
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $subject = sprintf("Remote Backup cleanup failed.");
                $this->addNotification($subject, $errorMessage);
            }
        }
    }
    private function refreshDropboxAccessToken(bool $delay)
    {
        try {
            if (true === $delay) {
                sleep(rand(5, 60));
            }
            $refreshToken = $this->getConfigValue("remote_backup_refresh_token");
            $dropboxClient = new DropboxClient();
            $token = $dropboxClient->getAccessToken($refreshToken);
            if (!(false === empty($token))) {
                throw new \Exception("Access Token cannot be empty.");
            }
            $rcloneConfigTemplate = new DropboxConfigTemplate();
            $rcloneConfigTemplate->setToken($token);
            $rcloneConfigBuilder = new RcloneConfigBuilder($rcloneConfigTemplate);
            $rcloneConfig = $rcloneConfigBuilder->build();
            $rclone = new Rclone();
            $rclone->writeConfig($rcloneConfig);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Refreshing Dropbox Access Token Failed", $errorMessage);
        }
    }
    private function addNotification(string $subject, string $errorMessage) : void
    {
        $notification = new Notification();
        $notification->setSubject($subject);
        $notification->setMessage($errorMessage);
        $notification->setSeverity(Notification::SEVERITY_CRITICAL);
        NotificationQueue::addNotification($notification);
    }
}