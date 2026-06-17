<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use App\Entity\Manager\NotificationManager;
use App\Notification\NotificationQueue;
use App\Service\Logger;
class NotificationsListener implements EventSubscriberInterface
{
    private NotificationManager $notificationManager;
    private Logger $logger;
    public function __construct(NotificationManager $notificationManager, Logger $logger)
    {
        $this->notificationManager = $notificationManager;
        $this->logger = $logger;
    }
    public function onTerminate() : void
    {
        $queuedNotifications = NotificationQueue::getQueuedNotifications();
        if (count($queuedNotifications)) {
            try {
                foreach ($queuedNotifications as $notification) {
                    $this->notificationManager->updateNotification($notification);
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
            }
        }
    }
    public static function getSubscribedEvents() : array
    {
        return [KernelEvents::TERMINATE => [["onTerminate", 50]], ConsoleEvents::TERMINATE => [["onTerminate", 50]]];
    }
}