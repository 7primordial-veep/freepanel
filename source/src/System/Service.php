<?php

namespace App\System;

use App\System\Command\ServiceStatusCommand;
use App\System\CommandExecutor;
class Service
{
    const SERVICE_STATUS_ACTIVE = "active";
    const SERVICE_STATUS_NONACTIVE = "nonactive";
    private ?string $name = null;
    private ?string $status = null;
    private ?string $serviceName = null;
    private CommandExecutor $commandExecutor;
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function setName(string $name) : void
    {
        $this->name = $name;
    }
    public function getName() : ?string
    {
        return $this->name;
    }
    public function setServiceName(string $serviceName) : void
    {
        $this->serviceName = $serviceName;
    }
    public function getServiceName() : ?string
    {
        return $this->serviceName;
    }
    public function setStatus(string $status) : void
    {
        $this->status = $status;
    }
    public function getStatus() : ?string
    {
        return $this->status;
    }
    public function isRunning() : ?string
    {
        $serviceName = $this->getServiceName();
        $serviceStatusCommand = new ServiceStatusCommand();
        $serviceStatusCommand->setServiceName($serviceName);
        if ("dev" == $_ENV["APP_ENV"]) {
            $serviceStatus = self::SERVICE_STATUS_ACTIVE;
        } else {
            try {
                $this->commandExecutor->execute($serviceStatusCommand);
                $serviceStatus = $serviceStatusCommand->getStatus();
            } catch (\Exception $e) {
                $serviceStatus = self::SERVICE_STATUS_NONACTIVE;
            }
        }
        $isRunning = self::SERVICE_STATUS_ACTIVE == $serviceStatus;
        return $isRunning;
    }
}