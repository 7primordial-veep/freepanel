<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
class Logger
{
    const SEVERITY_DEBUG = "debug";
    const SEVERITY_INFO = "info";
    const SEVERITY_ERROR = "error";
    private RequestStack $requestStack;
    private LoggerInterface $logger;
    public function __construct(RequestStack $requestStack, LoggerInterface $logger)
    {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }
    public function debug($message, array $context = [])
    {
        $this->logMessage(self::SEVERITY_DEBUG, $message, $context);
    }
    public function info($message, array $context = [])
    {
        $this->logMessage(self::SEVERITY_INFO, $message, $context);
    }
    public function error($message, array $context = [])
    {
        $this->logMessage(self::SEVERITY_ERROR, $message, $context);
    }
    public function exception(\Exception $e)
    {
        $errorMessage = sprintf("%s, Trace: %s", $e->getMessage(), $e->getTraceAsString());
        $errorMessage = substr($errorMessage, 0, 1499);
        return $this->error($errorMessage);
    }
    private function logMessage($severity, $message, $context = [])
    {
        switch ($severity) {
            case self::SEVERITY_DEBUG:
                $this->logger->debug($message, $context);
                break;
            case self::SEVERITY_INFO:
                $this->logger->info($message, $context);
                break;
            case self::SEVERITY_ERROR:
                return $this->logger->error($message, $context);
        }
    }
}