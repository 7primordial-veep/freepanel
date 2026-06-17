<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Command\Command as BaseCommand;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Entity\Notification;
use App\Vultr\Snapshot;
use App\Notification\NotificationQueue;
class VultrSnapshotCreateCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("vultr:snapshot:create");
        $this->setDescription("clpctl vultr:snapshot:create --frequency=3");
        $this->addOption("frequency", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $frequency = (int) $input->getOption("frequency");
            $cloud = $this->getConfigValue("cloud");
            $automaticSnapshots = (bool) $this->getConfigValue("vultr_automatic_snapshots");
            $apiKey = $this->getConfigValue("vultr_api_key");
            $snapshotsFrequency = (int) $this->getConfigValue("vultr_snapshots_frequency");
            if (CloudPanelEnvironment::CLOUD_PROVIDER_VULTR == $cloud && true === $automaticSnapshots && false === empty($apiKey) && $snapshotsFrequency == $frequency) {
                $snapshotCreated = $this->createSnapshot();
                if (true === $snapshotCreated) {
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
    private function createSnapshot() : bool
    {
        try {
            $instance = $this->getInstance();
            $vultrClient = $instance->getVultrClient();
            $vultrInstance = $vultrClient->getInstance();
            $snapshotName = sprintf("%s-%s", $vultrInstance->getLabel(), $vultrInstance->getId());
            $vultrClient->createSnapshot($snapshotName);
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Creating a Snapshot failed", $errorMessage);
        }
        return false;
    }
    private function cleanSnapshots() : bool
    {
        try {
            $instance = $this->getInstance();
            $vultrClient = $instance->getVultrClient();
            $backupRetentionPeriod = (int) $this->getConfigValue("vultr_snapshots_retention_period");
            $dateTime = new \DateTime();
            $snapshotDeleteDateTime = clone $dateTime;
            $snapshotDeleteDateTime->modify(sprintf("-%s days", $backupRetentionPeriod));
            $snapshotDeleteDateTime->setTimezone(new \DateTimeZone("UTC"));
            $snapshots = $vultrClient->getSnapshots();
            if (count($snapshots)) {
                foreach ($snapshots as $snapshot) {
                    $snapshotCreatedAt = $snapshot->getCreatedAt();
                    if (!(Snapshot::STATUS_COMPLETE == $snapshot->getStatus() && $snapshotCreatedAt < $snapshotDeleteDateTime)) {
                        continue;
                    }
                    $id = $snapshot->getId();
                    $vultrClient->deleteSnapshot($id);
                }
            }
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->addNotification("Cleaning Snapshots failed", $errorMessage);
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
}