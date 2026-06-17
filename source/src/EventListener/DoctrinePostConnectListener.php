<?php

namespace App\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Events;
class DoctrinePostConnectListener implements EventSubscriber
{
    public function getSubscribedEvents() : array
    {
        return [Events::postConnect];
    }
    public function postConnect(ConnectionEventArgs $args) : void
    {
        $connection = $args->getConnection();
        $databasePlatform = $connection->getDatabasePlatform();
        if ("sqlite" !== strtolower($databasePlatform->getName())) {
            return;
        }
        $connection->executeStatement("PRAGMA foreign_keys = ON;");
    }
}