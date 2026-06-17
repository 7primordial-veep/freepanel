<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use App\Service\Logger;
use App\Event\EventQueue;
use App\Entity\Manager\EventManager;
class EventListener implements EventSubscriberInterface
{
    private EventManager $eventManager;
    private Logger $logger;
    public function __construct(EventManager $eventManager, Logger $logger)
    {
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }
    public function onTerminate() : void
    {
        $queuedEvents = EventQueue::getQueuedEvents();
        if (count($queuedEvents)) {
            try {
                foreach ($queuedEvents as $event) {
                    $user = $event["user"];
                    $request = $event["request"];
                    $eventData = $event["eventData"];
                    $eventEntity = $this->eventManager->createEntity();
                    $eventEntity->setCreatedAt($event["eventTime"]);
                    $eventEntity->setEventName($event["eventName"]);
                    $eventEntity->setUserName($user->getUserName());
                    $eventEntity->setUserRole($user->getRole());
                    if (false === is_null($request)) {
                        $sourceIpAddress = $request->getClientIp();
                        $userAgent = $request->headers->get("User-Agent");
                        if (false === empty($sourceIpAddress)) {
                            $eventEntity->setSourceIpAddress($sourceIpAddress);
                        }
                        if (false === empty($userAgent)) {
                            $eventEntity->setUserAgent($userAgent);
                        }
                    }
                    if (false === empty($eventData)) {
                        $eventEntity->setEventData($eventData);
                    }
                    $this->eventManager->updateEntity($eventEntity);
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