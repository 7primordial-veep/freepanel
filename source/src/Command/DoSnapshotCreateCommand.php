<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Command\Command as BaseCommand;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Entity\Notification;
use App\Notification\NotificationQueue;
class DoSnapshotCreateCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("do:snapshot:create");
        $this->setDescription("clpctl do:snapshot:create --frequency=3");
        $this->addOption("frequency", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $frequency = (int) $input->getOption("frequency");
            $cloud = $this->getConfigValue("cloud");
            $automaticSnapshots = (bool) $this->getConfigValue("do_automatic_snapshots");
            $accessToken = $this->getConfigValue("do_access_token");
            $snapshotsFrequency = (int) $this->getConfigValue("do_snapshots_frequency");
            if (CloudPanelEnvironment::CLOUD_PROVIDER_DO == $cloud && true === $automaticSnapshots && false === empty($accessToken) && $snapshotsFrequency == $frequency) {
                $snapshotsCreated = $this->createSnapshots();
                if (true === $snapshotsCreated) {
                    $this->cleanSnapshots();
                }
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function createSnapshots()
    {
        $snapshotsCreated = $this->createDropletSnapshot();
        if (true === $snapshotsCreated) {
            $snapshotsCreated = $this->createDropletVolumeSnapshots();
        }
        return $snapshotsCreated;
    }
    private function createDropletSnapshot() : bool
    {
        try {
            $instance = $this->getInstance();
            $doClient = $instance->getDoClient();
            $droplet = $doClient->getDroplet();
            $dateTime = new \DateTime();
            $snapshotName = sprintf("%s-%s-clp", $droplet->getName(), $dateTime->getTimestamp());
            $doClient->createDropletSnapshot($snapshotName);
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Creating a Snapshot failed", $errorMessage);
        }
        return false;
    }
    private function createDropletVolumeSnapshots() : bool
    {
        try {
            $instance = $this->getInstance();
            $doClient = $instance->getDoClient();
            $droplet = $doClient->getDroplet();
            $dropletVolumeIds = $droplet->getVolumeIds();
            if (false === empty($dropletVolumeIds)) {
                foreach ($dropletVolumeIds as $volumeId) {
                    $doClient->createVolumeSnapshot($volumeId);
                }
            }
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Creating Droplet Volume Snapshots failed", $errorMessage);
        }
        return false;
    }
    private function cleanSnapshots()
    {
        $dropletSnapshotsCleaned = $this->cleanDropletSnapshots();
        if (true === $dropletSnapshotsCleaned) {
            $this->cleanDropletVolumeSnapshots();
        }
    }
    private function cleanDropletSnapshots() : bool
    {
        try {
            $instance = $this->getInstance();
            $doClient = $instance->getDoClient();
            $doBackupRetentionPeriod = (int) $this->getConfigValue("do_snapshots_retention_period");
            $dateTime = new \DateTime();
            $snapshotDeleteDateTime = clone $dateTime;
            $snapshotDeleteDateTime->modify(sprintf("-%s days", $doBackupRetentionPeriod));
            $snapshotDeleteDateTime->setTimezone(new \DateTimeZone("UTC"));
            $snapshots = $doClient->getSnapshotsForDroplet();
            foreach ($snapshots as $snapshot) {
                $snapshotCreatedAt = $snapshot->getCreatedAt();
                if (!($snapshotCreatedAt < $snapshotDeleteDateTime)) {
                    continue;
                }
                $snapshotId = $snapshot->getId();
                $doClient->deleteDropletSnapshot($snapshotId);
            }
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Cleaning Droplet Snapshots failed", $errorMessage);
        }
        return false;
    }
    private function cleanDropletVolumeSnapshots() : void
    {
        try {
            $instance = $this->getInstance();
            $doClient = $instance->getDoClient();
            $droplet = $doClient->getDroplet();
            $doBackupRetentionPeriod = (int) $this->getConfigValue("do_snapshots_retention_period");
            $dropletVolumeIds = $droplet->getVolumeIds();
            if (false === empty($dropletVolumeIds)) {
                $dateTime = new \DateTime();
                $snapshotDeleteDateTime = clone $dateTime;
                $snapshotDeleteDateTime->modify(sprintf("-%s days", $doBackupRetentionPeriod));
                $snapshotDeleteDateTime->modify("+5 minutes");
                $snapshotDeleteDateTime->setTimezone(new \DateTimeZone("UTC"));
                foreach ($dropletVolumeIds as $volumeId) {
                    $volumeSnapshots = $doClient->getVolumeSnapshots($volumeId);
                    if (!(false === empty($volumeSnapshots))) {
                        continue;
                    }
                    foreach ($volumeSnapshots as $volumeSnapshot) {
                        $volumeSnapshotId = $volumeSnapshot->getId();
                        $volumeSnapshotCreatedAt = $volumeSnapshot->getCreatedAt();
                        if (!($volumeSnapshotCreatedAt < $snapshotDeleteDateTime)) {
                            continue;
                        }
                        $doClient->deleteVolumeSnapshot($volumeSnapshotId);
                    }
                }
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Cleaning Droplet Volume Snapshots failed", $errorMessage);
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