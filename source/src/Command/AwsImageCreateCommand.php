<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Aws\Ec2\Ec2Client;
use Aws\Credentials\Credentials as AwsCredentials;
use App\Command\Command as BaseCommand;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Aws\Instance as AwsInstance;
use App\Aws\Ami as AwsAmi;
use App\Entity\Notification;
use App\Notification\NotificationQueue;
class AwsImageCreateCommand extends BaseCommand
{
    private ?Ec2Client $ec2Client = null;
    protected function configure() : void
    {
        $this->setName("aws:image:create");
        $this->setDescription("clpctl aws:image:create --frequency=3");
        $this->addOption("frequency", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $frequency = (int) $input->getOption("frequency");
            $cloud = $this->getConfigValue("cloud");
            $awsAutomaticImages = (bool) $this->getConfigValue("aws_automatic_images");
            $awsImagesFrequency = (int) $this->getConfigValue("aws_images_frequency");
            $awsAccessKey = $this->getConfigValue("aws_access_key");
            if (CloudPanelEnvironment::CLOUD_PROVIDER_AWS == $cloud && true === $awsAutomaticImages && false === empty($awsAccessKey) && $awsImagesFrequency == $frequency) {
                $imageCreated = $this->createImage();
                if (true === $imageCreated) {
                    $this->cleanImages();
                }
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function createImage() : bool
    {
        try {
            $instanceUid = $this->getConfigValue("instance_uid");
            $instance = $this->getInstance();
            $instanceId = $instance->getInstanceId();
            $ec2Client = $this->getEc2Client();
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
                    $imageName = sprintf("%s_clp_%s", strtolower($instanceName), $dateTime->getTimestamp());
                } else {
                    $imageName = sprintf("%s_clp_%s", $instanceId, $dateTime->getTimestamp());
                }
                $imageConfiguration = ["InstanceId" => $instanceId, "Name" => $imageName, "NoReboot" => true];
                $imageId = $this->retry(function () use($ec2Client, $imageConfiguration) {
                    $result = $ec2Client->createImage($imageConfiguration);
                    $imageId = $result["ImageId"] ?? '';
                    return $imageId;
                });
                $this->retry(function () use($instanceUid, $imageId, $ec2Client) {
                    $ec2Client->createTags(["Resources" => [$imageId], "Tags" => [["Key" => "InstanceUid", "Value" => $instanceUid], ["Key" => "Type", "Value" => AwsAmi::TYPE_AUTOMATED], ["Key" => "CreatedBy", "Value" => "CloudPanel"]]]);
                });
            }
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Creating an AWS image failed", $errorMessage);
        }
        return false;
    }
    private function addNotification(string $subject, string $errorMessage) : void
    {
        $notification = new Notification();
        $notification->setSubject($subject);
        $notification->setMessage($errorMessage);
        $notification->setSeverity(Notification::SEVERITY_CRITICAL);
        NotificationQueue::addNotification($notification);
    }
    private function cleanImages() : void
    {
        try {
            $instanceUid = $this->getConfigValue("instance_uid");
            $awsBackupRetentionPeriod = (int) $this->getConfigValue("aws_images_retention_period");
            $ec2Client = $this->getEc2Client();
            $filters = [["Name" => "tag:InstanceUid", "Values" => [$instanceUid]], ["Name" => "tag:Type", "Values" => [AwsAmi::TYPE_AUTOMATED]], ["Name" => "state", "Values" => [AwsAmi::STATE_AVAILABLE, AwsAmi::STATE_FAILED]]];
            $result = $ec2Client->describeImages(["Filters" => $filters]);
            $images = (array) $result->get("Images");
            $dateTime = new \DateTime();
            $imageDeleteDateTime = clone $dateTime;
            $imageDeleteDateTime->modify(sprintf("-%s days", $awsBackupRetentionPeriod));
            $imageDeleteDateTime->modify("+5 minutes");
            $imageDeleteDateTime->setTimezone(new \DateTimeZone("UTC"));
            foreach ($images as $image) {
                if (empty($image["CreationDate"]) || empty($image["ImageId"])) { continue; }
                $imageCreationDate = new \DateTime($image["CreationDate"]);
                $imageCreationDate->setTimezone(new \DateTimeZone("UTC"));
                $amiId = $image["ImageId"];
                if ($imageCreationDate >= $imageDeleteDateTime) { continue; }
                $this->retry(function () use($ec2Client, $amiId) {
                    $ec2Client->deregisterImage(["ImageId" => $amiId]);
                });
                $blockDeviceMappings = $image["BlockDeviceMappings"] ?? [];
                if (!(false == empty($blockDeviceMappings) && true === is_array($blockDeviceMappings))) {
                    continue;
                }
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
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Cleaning old AWS images failed", $errorMessage);
        }
    }
    private function getEc2Client() : Ec2Client
    {
        if (true === is_null($this->ec2Client)) {
            $instance = $this->getInstance();
            $region = $instance->getRegion();
            $accessKey = $this->getConfigValue("aws_access_key");
            $secretAccessKey = $this->getConfigValue("aws_secret_access_key");
            $credentials = new AwsCredentials($accessKey, $secretAccessKey);
            $this->ec2Client = new Ec2Client(["version" => "latest", "region" => $region, "credentials" => $credentials]);
        }
        return $this->ec2Client;
    }
}