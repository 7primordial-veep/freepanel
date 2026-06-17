<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Command\Command as BaseCommand;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\Entity\Notification;
use App\Hetzner\Snapshot;
use App\Notification\NotificationQueue;
class HetznerSnapshotCreateCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("hetzner:snapshot:create");
        $this->setDescription("clpctl hetzner:snapshot:create --frequency=3");
        $this->addOption("frequency", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $frequency = (int) $input->getOption("frequency");
            $cloud = $this->getConfigValue("cloud");
            $automaticSnapshots = (bool) $this->getConfigValue("hetzner_automatic_snapshots");
            $apiToken = $this->getConfigValue("hetzner_api_token");
            $snapshotsFrequency = (int) $this->getConfigValue("hetzner_snapshots_frequency");
            if (CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER == $cloud && true === $automaticSnapshots && false === empty($apiToken) && $snapshotsFrequency == $frequency) {
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
            $hetznerClient = $instance->getHetznerClient();
            $snapshotName = $hetznerClient->getInstanceName();
            if (false === empty($snapshotName)) {
                $dateTime = new \DateTime();
                $dateTime->setTimezone(new \DateTimeZone("UTC"));
                $snapshotName = sprintf("%s-%s", $snapshotName, $dateTime->format("c"));
            }
            $hetznerClient->createSnapshot($snapshotName);
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
            $hetznerClient = $instance->getHetznerClient();
            $backupRetentionPeriod = (int) $this->getConfigValue("hetzner_snapshots_retention_period");
            $dateTime = new \DateTime();
            $snapshotDeleteDateTime = clone $dateTime;
            $snapshotDeleteDateTime->modify(sprintf("-%s days", $backupRetentionPeriod));
            $snapshotDeleteDateTime->modify("+5 minutes");
            $snapshotDeleteDateTime->setTimezone(new \DateTimeZone("UTC"));
            $snapshots = $hetznerClient->getSnapshots();
            if (count($snapshots)) {
                foreach ($snapshots as $snapshot) {
                    $snapshotCreatedAt = $snapshot->getCreatedAt();
                    $isSnapshotDeleteProtected = $snapshot->isDeleteProtected();
                    if (!(Snapshot::STATUS_AVAILABLE == $snapshot->getStatus() && $snapshotCreatedAt < $snapshotDeleteDateTime && false === $isSnapshotDeleteProtected)) {
                        continue;
                    }
                    $id = $snapshot->getId();
                    $hetznerClient->deleteSnapshot($id);
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