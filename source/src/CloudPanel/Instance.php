<?php

namespace App\CloudPanel;

use GuzzleHttp\Client as HttpClient;
use App\Util\Retry;
use App\System\CommandExecutor;
use App\System\Command\SedCommand;
use App\System\Command\MemoryInformationCommand;
use App\System\Command\ProcessorCoresCommand;
use App\System\Command\ServiceRestartCommand;
use App\System\Command\SetTimezoneCommand;
use App\Util\HumanFileSize as HumanFileSizeUtil;
use App\System\Service;
class Instance
{
    const HTTP_CLIENT_TIMEOUT = 5;
    const ARCHITECTURE_ARM = "aarch64";
    const ARCHITECTURE_X86 = "x86_64";
    const OPERATING_SYSTEM_DEBIAN = "Debian";
    const OPERATING_SYSTEM_UBUNTU = "Ubuntu";
    const PHP_DIRECTORY = "/etc/php/";
    const PROFTPD_SERVICE_NAME = "proftpd";
    const PROFTPD_CONFIG_FILE = "/etc/proftpd/proftpd.conf";
    protected ?string $uid = null;
    protected ?string $architecture = null;
    protected ?string $hostname = null;
    protected ?string $ipv4PublicIp = null;
    protected ?string $ipv6PublicIp = null;
    protected ?string $region = null;
    protected ?string $operatingSystemName = null;
    protected ?string $operatingSystemVersion = null;
    protected array $services = [];
    protected array $phpVersions = [];
    protected CommandExecutor $commandExecutor;
    protected Environment $environment;
    protected ?HttpClient $httpClient = null;
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }
    public function getArchitecture() : ?string
    {
        if (true === is_null($this->architecture)) {
            $this->architecture = php_uname("m");
        }
        return $this->architecture;
    }
    public function isArmArchitecture() : bool
    {
        $architecture = $this->getArchitecture();
        $isArmArchitecture = self::ARCHITECTURE_ARM == $architecture;
        return $isArmArchitecture;
    }
    public function getUid() : string
    {
        return $this->uid;
    }
    public function setUid(string $uid) : void
    {
        $this->uid = $uid;
    }
    public function setHostname(string $hostname) : void
    {
        $this->hostname = $hostname;
    }
    public function getHostname() : string
    {
        return $this->hostname;
    }
    public function setRegion(string $region) : void
    {
        $this->region = $region;
    }
    public function getRegion() : ?string
    {
        return $this->region;
    }
    public function setIpv4PublicIp(string $ipv4PublicIp) : void
    {
        $this->ipv4PublicIp = $ipv4PublicIp;
    }
    public function getIpv4PublicIp() : ?string
    {
        return $this->ipv4PublicIp;
    }
    public function setIpv6PublicIp(string $ipv6PublicIp) : void
    {
        $this->ipv6PublicIp = $ipv6PublicIp;
    }
    public function getIpv6PublicIp() : string
    {
        return $this->ipv6PublicIp;
    }
    public function setEnvironment(Environment $environment) : void
    {
        $this->environment = $environment;
    }
    public function getEnvironment() : Environment
    {
        return $this->environment;
    }
    public function getServices() : array
    {
        if (true === empty($this->services)) {
            $nginxService = new Service();
            $nginxService->setName("NGINX");
            $nginxService->setServiceName("nginx");
            $mysqlService = new Service();
            $mysqlService->setName("MySQL");
            $mysqlService->setServiceName("mysql");
            $redisServer = new Service();
            $redisServer->setName("Redis Server");
            $redisServer->setServiceName("redis-server");
            $this->services[$nginxService->getServiceName()] = $nginxService;
            $this->services[$mysqlService->getServiceName()] = $mysqlService;
            $this->services[$redisServer->getServiceName()] = $redisServer;
            $phpVersions = $this->getPhpVersions();
            if (count($phpVersions)) {
                foreach ($phpVersions as $phpVersion) {
                    $phpService = new Service();
                    $phpService->setName(sprintf("PHP-FPM %s", $phpVersion));
                    $phpService->setServiceName(sprintf("php%s-fpm", $phpVersion));
                    $this->services[$phpService->getServiceName()] = $phpService;
                }
            }
            uasort($this->services, function ($a, $b) {
                return $a->getName() > $b->getName();
            });
            $varnishCache = new Service();
            $varnishCache->setName("Varnish Cache");
            $varnishCache->setServiceName("varnish");
            $this->services[$varnishCache->getServiceName()] = $varnishCache;
        }
        return $this->services;
    }
    public function restartService(Service $service) : void
    {
        $serviceName = $service->getServiceName();
        $serviceRestartCommand = new ServiceRestartCommand();
        $serviceRestartCommand->setServiceName($serviceName);
        $this->commandExecutor->execute($serviceRestartCommand);
    }
    public function getOperatingSystemName() : string
    {
        $this->operatingSystemName = false === file_exists("/etc/lsb-release") ? self::OPERATING_SYSTEM_DEBIAN : self::OPERATING_SYSTEM_UBUNTU;
        return $this->operatingSystemName;
    }
    public function operatingSystemVersion() : string
    {
        $operatingSystemName = $this->getOperatingSystemName();
        switch ($operatingSystemName) {
            case self::OPERATING_SYSTEM_DEBIAN:
                $this->operatingSystemVersion = trim(file_get_contents("/etc/debian_version"));
                break;
            case self::OPERATING_SYSTEM_UBUNTU:
                $osReleaseInformation = explode(PHP_EOL, trim(file_get_contents("/etc/os-release")));
                $operatingSystemVersionId = $osReleaseInformation[2] ?? '';
                $operatingSystemVersion = substr($operatingSystemVersionId, 12, -1);
                $this->operatingSystemVersion = $operatingSystemVersion;
                break;
        }
        return $this->operatingSystemVersion;
    }
    public function getPhpVersions() : array
    {
        if (true === empty($this->phpVersions)) {
            foreach (new \DirectoryIterator(self::PHP_DIRECTORY) as $fileInfo) {
                if (!(false === $fileInfo->isDot())) {
                    continue;
                }
                $phpVersion = $fileInfo->getBasename();
                if (!(true === is_float($phpVersion + 0))) {
                    continue;
                }
                $this->phpVersions[] = $phpVersion;
            }
            rsort($this->phpVersions);
        }
        return $this->phpVersions;
    }
    public function getProcessorCores() : int
    {
        $processorCoresCommand = new ProcessorCoresCommand();
        $this->commandExecutor->execute($processorCoresCommand);
        $numberOfProcessorCores = $processorCoresCommand->getNumberOfProcessorCores();
        return $numberOfProcessorCores;
    }
    public function getMemory($unit = "GB") : string
    {
        $totalMemoryInBytes = $this->getTotalMemoryInBytes();
        $memory = HumanFileSizeUtil::convert($totalMemoryInBytes, $unit, 0);
        return $memory;
    }
    private function getTotalMemoryInBytes() : int
    {
        $memoryInformationCommand = new MemoryInformationCommand();
        $this->commandExecutor->execute($memoryInformationCommand);
        $totalMemoryInBytes = $memoryInformationCommand->getTotalMemoryInBytes();
        return $totalMemoryInBytes;
    }
    public function setProftpdMasqueradeAddress($masqueradeAddress) : void
    {
        $sedCommand = new SedCommand();
        $sedCommand->setFile(self::PROFTPD_CONFIG_FILE);
        $sedCommand->setPattern(sprintf("s/MasqueradeAddress.*/MasqueradeAddress %s/g", $masqueradeAddress));
        $serviceRestartCommand = new ServiceRestartCommand();
        $serviceRestartCommand->setServiceName(self::PROFTPD_SERVICE_NAME);
        $this->commandExecutor->execute($sedCommand);
        $this->commandExecutor->execute($serviceRestartCommand);
    }
    public function setTimezone(string $timezone) : void
    {
        $setTimezoneCommand = new SetTimezoneCommand();
        $setTimezoneCommand->setTimezone($timezone);
        $this->commandExecutor->execute($setTimezoneCommand);
    }
    public function reboot($delay = 5) : void
    {
        shell_exec(sprintf("(/bin/sleep %s && /usr/bin/sudo reboot) > /dev/null &", $delay));
    }
    protected function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
    protected function retry(callable $fn, $retries = 2, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}