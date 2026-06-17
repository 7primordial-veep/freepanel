<?php

namespace App\Transformer;

use App\Entity\Event;
use App\Entity\User;
class EventDataTransformer
{
    private Event $event;
    private User $user;
    public function __construct(Event $event, User $user)
    {
        $this->event = $event;
        $this->user = $user;
    }
    public function transform() : void
    {
        $eventCreatedAt = $this->event->getCreatedAt();
        $eventCreatedAtUserTimezone = clone $eventCreatedAt;
        $eventCreatedAtUserTimezone->setTimezone(new \DateTimeZone($this->user->getTimezone()));
        $eventData = ["eventTime" => $eventCreatedAtUserTimezone->format("M j, Y H:i:s"), "userName" => $this->event->getUserName(), "eventName" => $this->event->getEventName(), "sourceIpAddress" => $this->event->getSourceIpAddress(), "userAgent" => $this->event->getUserAgent()];
        if (false === empty($this->event->getEventData())) {
            $eventData = array_merge($eventData, $this->event->getEventData());
        }
        $this->event->setEventData($eventData);
    }
}