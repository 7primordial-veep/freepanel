<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Command\Command as BaseCommand;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Entity\Notification;
use App\Gce\Snapshot;
use App\Notification\NotificationQueue;
class GceSnapshotCreateCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("gce:snapshot:create");
        $this->setDescription("clpctl gce:snapshot:create --frequency=3");
        $this->addOption("frequency", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $frequency = (int) $input->getOption("frequency");
            $cloud = $this->getConfigValue("cloud");
            $automaticSnapshots = (bool) $this->getConfigValue("gce_automatic_snapshots");
            $serviceAccountKeys = $this->getConfigValue("gce_service_account_keys");
            $snapshotsFrequency = (int) $this->getConfigValue("gce_snapshots_frequency");
            if (CloudPanelEnvironment::CLOUD_PROVIDER_GCE == $cloud && true === $automaticSnapshots && false === empty($serviceAccountKeys) && $snapshotsFrequency == $frequency) {
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
    private function createSnapshots() : bool
    {
        try {
            $instance = $this->getInstance();
            $gceClient = $instance->getGceClient();
            $instance = $gceClient->getInstance();
            $instanceName = $instance->getName();
            $gceClient->createDiskSnapshots($instanceName, Snapshot::TYPE_AUTOMATED);
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Creating a Snapshot failed", $errorMessage);
        }
        return false;
    }
    private function cleanSnapshots() : void
    {
        try {
            $instance = $this->getInstance();
            $gceClient = $instance->getGceClient();
            $snapshots = $gceClient->getSnapshots();
            if (false === empty($snapshots)) {
                $backupRetentionPeriod = (int) $this->getConfigValue("gce_snapshots_retention_period");
                $dateTime = new \DateTime();
                $snapshotDeleteDateTime = clone $dateTime;
                $snapshotDeleteDateTime->modify(sprintf("-%s days", $backupRetentionPeriod));
                $snapshotDeleteDateTime->modify("+5 minutes");
                $snapshotDeleteDateTime->setTimezone(new \DateTimeZone("UTC"));
                foreach ($snapshots as $snapshot) {
                    $id = $snapshot->getId();
                    $status = $snapshot->getStatus();
                    $type = $snapshot->getType();
                    if (!(Snapshot::STATUS_READY == $status && Snapshot::TYPE_AUTOMATED == $type)) {
                        continue;
                    }
                    $createdAt = $snapshot->getCreatedAt();
                    if (!($createdAt < $snapshotDeleteDateTime)) {
                        continue;
                    }
                    $gceClient->deleteSnapshot($id);
                }
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Cleaning Snapshots failed", $errorMessage);
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