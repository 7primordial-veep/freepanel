<?php

namespace App\Backup;

use App\System\CommandExecutor;
use App\System\Command\ChownCommand;
use App\System\Command\FindChmodCommand;
use App\System\Command\CreateDirectoryCommand;
use App\System\Command\DeleteDirectoryCommand;
use App\System\Command\WriteFileCommand;
use App\System\Command\DeleteFileCommand;
use App\System\Command\RcloneCopyCommand;
use App\System\Command\RcloneDeleteFileCommand;
use App\System\Command\RcloneLsJsonCommand;
use App\System\Command\RclonePurgeCommand;
use App\System\Command\TarStreamUploadCommand;
use App\System\Command\ChmodCommand;
class Rclone
{
    private const FREQUENCY_DAILY = "daily";
    private const CONFIG_DIRECTORY = "/root/.config/rclone/";
    private const CLP_USER_CONFIG_DIRECTORY = "/home/clp/.config/";
    private const CLP_USER = "clp";
    public const CREDENTIALS_DIRECTORY = "/home/clp/.config/rclone/credentials/";
    private const CONFIG_FILE = "/root/.config/rclone/rclone.conf";
    private const CRON_JOB_FILE = "/etc/cron.d/clp-rclone";
    private CommandExecutor $commandExecutor;
    private ?string $configFile = null;
    private array $flags = [];
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function setConfigFile(string $configFile) : void
    {
        $this->configFile = $configFile;
    }
    public function getConfigFile() : ?string
    {
        if (true === is_null($this->configFile)) {
            $this->configFile = self::CONFIG_FILE;
        }
        return $this->configFile;
    }
    public function addFlag(string $flag, string $value)
    {
        $this->flags[] = ["flag" => $flag, "value" => $value];
    }
    public function getFlags() : array
    {
        return $this->flags;
    }
    public function copy(string $source, string $destination) : void
    {
        $flags = $this->getFlags();
        $configFile = $this->getConfigFile();
        $rcloneCopyCommand = new RcloneCopyCommand();
        $rcloneCopyCommand->setConfigFile($configFile);
        $rcloneCopyCommand->setSource($source);
        $rcloneCopyCommand->setDestination($destination);
        $this->addFlagsToCommand($flags, $rcloneCopyCommand);
        $this->commandExecutor->execute($rcloneCopyCommand, 21600);
    }
    public function lsJson(?string $remotePath = null, $directoriesOnly = false) : array
    {
        $flags = $this->getFlags();
        $configFile = $this->getConfigFile();
        $rcloneLsJsonCommand = new RcloneLsJsonCommand();
        $rcloneLsJsonCommand->setConfigFile($configFile);
        $rcloneLsJsonCommand->setRemotePath($remotePath);
        if (true === $directoriesOnly) {
            $rcloneLsJsonCommand->setDirectoriesOnly(true);
        }
        $this->addFlagsToCommand($flags, $rcloneLsJsonCommand);
        $this->commandExecutor->execute($rcloneLsJsonCommand, 20);
        $files = $rcloneLsJsonCommand->getFiles();
        return $files;
    }
    public function streamUpload(array $sources, array $excludes, string $destinationObject) : void
    {
        $flags = $this->getFlags();
        $configFile = $this->getConfigFile();
        $command = new TarStreamUploadCommand();
        $command->setSources($sources);
        $command->setExcludes($excludes);
        $command->setRcloneConfigFile($configFile);
        $command->setDestinationObject($destinationObject);
        foreach ($flags as $flagData) {
            $command->addRcloneFlag($flagData['flag'], $flagData['value']);
        }
        $this->commandExecutor->execute($command, 21600);
    }

    public function deleteFile(string $remotePath) : void
    {
        $flags = $this->getFlags();
        $configFile = $this->getConfigFile();
        $command = new RcloneDeleteFileCommand();
        $command->setConfigFile($configFile);
        $command->setRemotePath($remotePath);
        $this->addFlagsToCommand($flags, $command);
        $this->commandExecutor->execute($command, 120);
    }

    public function purge(string $remotePath)
    {
        $flags = $this->getFlags();
        $rclonePurgeCommand = new RclonePurgeCommand();
        $rclonePurgeCommand->setRemotePath($remotePath);
        $this->addFlagsToCommand($flags, $rclonePurgeCommand);
        $this->commandExecutor->execute($rclonePurgeCommand, 21600);
    }
    public function writeConfig(string $config) : void
    {
        $configFile = $this->getConfigFile();
        $createConfigDirectoryCommand = new CreateDirectoryCommand();
        $createConfigDirectoryCommand->setDirectory(self::CONFIG_DIRECTORY);
        $writeConfigFileCommand = new WriteFileCommand();
        $writeConfigFileCommand->setFile($configFile);
        $writeConfigFileCommand->setContent($config);
        $this->commandExecutor->execute($createConfigDirectoryCommand);
        $this->commandExecutor->execute($writeConfigFileCommand);
        $chmodConfigFileCommand = new ChmodCommand();
        $chmodConfigFileCommand->setFile($configFile);
        $chmodConfigFileCommand->setChmod("0600");
        $this->commandExecutor->execute($chmodConfigFileCommand);
    }
    public function writeCredentialsFile(string $file, string $content) : void
    {
        $credentialsDirectoryCommand = new CreateDirectoryCommand();
        $credentialsDirectoryCommand->setDirectory(self::CREDENTIALS_DIRECTORY);
        $chownConfigDirectoryCommand = new ChownCommand();
        $chownConfigDirectoryCommand->setFile(self::CLP_USER_CONFIG_DIRECTORY);
        $chownConfigDirectoryCommand->setRecursive(true);
        $chownConfigDirectoryCommand->setUser(self::CLP_USER);
        $chownConfigDirectoryCommand->setGroup(self::CLP_USER);
        $chmodConfigDirectoryCommand = new FindChmodCommand();
        $chmodConfigDirectoryCommand->setFile(self::CLP_USER_CONFIG_DIRECTORY);
        $chmodConfigDirectoryCommand->setDirectoryChmod(770);
        $chmodConfigDirectoryCommand->setFileChmod(770);
        $this->commandExecutor->execute($credentialsDirectoryCommand);
        $this->commandExecutor->execute($chownConfigDirectoryCommand);
        $this->commandExecutor->execute($chmodConfigDirectoryCommand);
        file_put_contents($file, $content);
        @chmod($file, 0600);
    }
    public function createCronJob(string $frequency, ?string $executionTime = null) : void
    {
        $cronJobContent = sprintf("MAILTO=\"\"%s", PHP_EOL);
        if (self::FREQUENCY_DAILY == $frequency) {
            $cronJobContent .= sprintf("0 %s * * * clp /usr/bin/bash -c \"/usr/bin/clpctl remote-backup:create --delay=true\" &> /dev/null", $executionTime);
        } else {
            $cronJobContent .= sprintf("0 */%s * * * clp /usr/bin/bash -c \"/usr/bin/clpctl remote-backup:create --delay=true\" &> /dev/null", $frequency);
        }
        $writeCronJobFileCommand = new WriteFileCommand();
        $writeCronJobFileCommand->setFile(self::CRON_JOB_FILE);
        $writeCronJobFileCommand->setContent($cronJobContent);
        $this->commandExecutor->execute($writeCronJobFileCommand);
    }
    private function addFlagsToCommand(array $flags, $command) : void
    {
        foreach ($flags as $flagData) {
            $command->addFlag($flagData["flag"], $flagData["value"]);
        }
    }
    public function deleteCronJob() : void
    {
        $deleteCronJobFileCommand = new DeleteFileCommand();
        $deleteCronJobFileCommand->setFile(self::CRON_JOB_FILE);
        $this->commandExecutor->execute($deleteCronJobFileCommand);
    }
    public function deleteCredentials() : void
    {
        $deleteCredentialsDirectoryCommand = new DeleteDirectoryCommand();
        $deleteCredentialsDirectoryCommand->setDirectory(self::CREDENTIALS_DIRECTORY);
        $this->commandExecutor->execute($deleteCredentialsDirectoryCommand);
    }
}