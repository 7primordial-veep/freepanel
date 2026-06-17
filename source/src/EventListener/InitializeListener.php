<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Cache\CacheItemPoolInterface as CachePool;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use Twig\Environment as TwigEnvironment;
use App\Entity\Manager\ConfigManager;
use App\Entity\Manager\UserManager;
use App\Entity\Manager\DatabaseServerManager;
use App\Command\Command;
use App\Util\PasswordGenerator;
use App\CloudPanel\Environment as CloudPanelEnvironment;
use App\CloudPanel\Aws\Instance as AwsInstance;
use App\CloudPanel\Do\Instance as DoInstance;
use App\CloudPanel\Gce\Instance as GceInstance;
use App\CloudPanel\Hetzner\Instance as HetznerInstance;
use App\CloudPanel\Vultr\Instance as VultrInstance;
use App\CloudPanel\Instance;
use App\Do\Client as DoClient;
use App\Gce\Client as GceClient;
use App\Hetzner\Client as HetznerClient;
use App\Vultr\Client as VultrClient;
use App\System\CommandExecutor;
use App\System\Command\ChangeDatabaseUserPasswordCommand;
use App\Entity\DatabaseServer;
use App\Service\Crypto;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Database\Connection as DatabaseConnection;
use App\Util\Retry;
class InitializeListener implements EventSubscriberInterface
{
    private const IP_REQUEST_URL = "https://d3qnd54q8gb3je.cloudfront.net/";
    private ConfigManager $configManager;
    private DatabaseServerManager $databaseServerManager;
    private UserManager $userManager;
    private CachePool $cachePool;
    private TwigEnvironment $twig;
    public function __construct(ConfigManager $configManager, DatabaseServerManager $databaseServerManager, UserManager $userManager, CachePool $cachePool, TwigEnvironment $twig)
    {
        $this->configManager = $configManager;
        $this->databaseServerManager = $databaseServerManager;
        $this->userManager = $userManager;
        $this->cachePool = $cachePool;
        $this->twig = $twig;
    }
    public function onKernelRequest(RequestEvent $event) : void
    {
        if (false === $event->isMainRequest()) {
            return;
        }
        $this->initialize($event);
    }
    public function onConsoleCommand(ConsoleCommandEvent $event) : void
    {
        $command = $event->getCommand();
        if ($command instanceof Command) {
            $this->initialize($event);
        }
    }
    private function initialize(RequestEvent|ConsoleCommandEvent $event) : void
    {
        $this->setTimezone();
        $this->setInstanceUid();
        $this->setCloudPanelVersion();
        $this->setCloudPanelReleaseChannel();
        $this->setLetsEncryptPrivateKey();
        $this->setDatabaseServer($event);
        $this->setEnvironment($event);
    }
    private function setTimezone() : void
    {
        $timezone = $this->configManager->get("timezone");
        if (true === is_null($timezone)) {
            $this->configManager->set("timezone", "UTC");
        }
    }
    private function setInstanceUid() : void
    {
        $instanceUid = $this->configManager->get("instance_uid");
        if (true === is_null($instanceUid)) {
            $instanceUid = substr(sha1(uniqid(mt_rand(), true)), 0, 16);
            $this->configManager->set("instance_uid", $instanceUid);
        }
    }
    private function setCloudPanelVersion() : void
    {
        $appVersion = $_ENV["APP_VERSION"] ?? null;
        if (false === is_null($appVersion)) {
            $this->configManager->set("app_version", trim($appVersion));
        }
    }
    private function setCloudPanelReleaseChannel() : void
    {
        $releaseChannel = $this->configManager->get("release_channel");
        if (true === is_null($releaseChannel)) {
            $this->configManager->set("release_channel", CloudPanelEnvironment::RELEASE_CHANNEL_STABLE);
        }
    }
    private function setLetsEncryptPrivateKey()
    {
        $numberOfUsers = $this->userManager->countAll();
        if (0 < $numberOfUsers) {
            $letsEncryptPrivateKey = $this->configManager->get("le_private_key");
            if (true === is_null($letsEncryptPrivateKey)) {
                $rsaKeyGenerator = new RsaKeyGenerator();
                $privateKey = $rsaKeyGenerator->generatePrivateKey();
                $this->configManager->set("le_private_key", $privateKey->getPEM());
            }
        }
    }
    private function setDatabaseServer(RequestEvent|ConsoleCommandEvent $event) : void
    {
        $numberOfUsers = $this->userManager->countAll();
        $databaseServers = $this->databaseServerManager->findAll();
        if (0 < $numberOfUsers && 0 == count($databaseServers)) {
            if ("dev" == $_ENV["APP_ENV"]) {
                $password = "root";
            } else {
                $passwordGenerator = new PasswordGenerator();
                $password = $passwordGenerator->generate();
            }
            $encryptedPassword = Crypto::encrypt($password);
            $databaseServer = $this->databaseServerManager->createEntity();
            $databaseServer->setHost("127.0.0.1");
            $databaseServer->setUserName("root");
            $databaseServer->setPassword(Crypto::encrypt("root"));
            $databaseServer->setPort(DatabaseServer::DEFAULT_PORT);
            $databaseServer->setIsActive(true);
            $databaseServer->setIsDefault(true);
            $databaseConnection = new DatabaseConnection($databaseServer);
            $databaseServer->setEngine($databaseConnection->getEngine());
            $databaseServer->setVersion($databaseConnection->getVersion());
            $databaseServer->setPassword($encryptedPassword);
            if ("dev" != $_ENV["APP_ENV"]) {
                $tmpDatabaseServer = clone $databaseServer;
                $tmpDatabaseServer->setPassword("root");
                $commandExecutor = new CommandExecutor();
                $changeDatabaseUserPasswordCommand = new ChangeDatabaseUserPasswordCommand();
                $changeDatabaseUserPasswordCommand->setUserName("root");
                $changeDatabaseUserPasswordCommand->setNewPassword($password);
                $changeDatabaseUserPasswordCommand->setDatabaseServer($tmpDatabaseServer);
                $commandExecutor->execute($changeDatabaseUserPasswordCommand);
            }
            $this->databaseServerManager->updateEntity($databaseServer);
        }
    }
    private function setEnvironment(RequestEvent|ConsoleCommandEvent $event) : void
    {
        $cloud = $this->configManager->get("cloud");
        $environment = new CloudPanelEnvironment();
        switch ($cloud) {
            case CloudPanelEnvironment::CLOUD_PROVIDER_AWS:
                $environment->setCloudProvider(CloudPanelEnvironment::CLOUD_PROVIDER_AWS);
                $instance = new AwsInstance();
                if (!("dev" == $_ENV["APP_ENV"])) {
                    break;
                }
                $instanceId = $_ENV["DEV_AWS_INSTANCE_ID"] ?? '';
                $region = $_ENV["DEV_AWS_REGION"] ?? '';
                $instance->setInstanceId($instanceId);
                $instance->setInstanceType("t3.xlarge");
                $instance->setRegion($region);
            case CloudPanelEnvironment::CLOUD_PROVIDER_DO:
                $environment->setCloudProvider(CloudPanelEnvironment::CLOUD_PROVIDER_DO);
                $doClient = new DoClient();
                $token = $this->configManager->get("do_access_token");
                if (false === is_null($token)) {
                    $doClient->setToken($token);
                }
                $instance = new DoInstance($doClient);
                $doClient->setInstance($instance);
                if (!("dev" == $_ENV["APP_ENV"])) {
                    break;
                }
                $dropletId = $_ENV["DEV_DO_DROPLET_ID"] ?? '';
                $region = $_ENV["DEV_DO_REGION"] ?? '';
                $instance->setDropletId($dropletId);
                $instance->setRegion($region);
                $instance->setFloatingIp("127.0.0.1");
                $instance->setIpv4PublicIp("127.0.0.1");
            case CloudPanelEnvironment::CLOUD_PROVIDER_GCE:
                $environment->setCloudProvider(CloudPanelEnvironment::CLOUD_PROVIDER_GCE);
                $gceClient = new GceClient();
                $serviceAccountKeys = $this->configManager->get("gce_service_account_keys");
                if (false === is_null($serviceAccountKeys)) {
                    $serviceAccountKeys = (array) json_decode($serviceAccountKeys, true);
                    $gceClient->setAuthConfig($serviceAccountKeys);
                }
                $instance = new GceInstance($gceClient);
                $gceClient->setGceInstance($instance);
                if (!("dev" == $_ENV["APP_ENV"])) {
                    break;
                }
                $projectId = $_ENV["DEV_GCE_PROJECT_ID"] ?? '';
                $instanceId = $_ENV["DEV_GCE_INSTANCE_ID"] ?? '';
                $instanceName = $_ENV["DEV_GCE_INSTANCE_NAME"] ?? '';
                $machineType = $_ENV["DEV_GCE_MACHINE_TYPE"] ?? '';
                $zone = $_ENV["DEV_GCE_ZONE"] ?? '';
                $instance->setIpv4PublicIp("127.0.0.1");
                $instance->setProjectId($projectId);
                $instance->setInstanceId($instanceId);
                $instance->setInstanceName($instanceName);
                $instance->setMachineType($machineType);
                $instance->setZone($zone);
            case CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER:
                $environment->setCloudProvider(CloudPanelEnvironment::CLOUD_PROVIDER_HETZNER);
                $hetznerClient = new HetznerClient();
                $apiToken = $this->configManager->get("hetzner_api_token");
                if (false === is_null($apiToken)) {
                    $hetznerClient->setToken($apiToken);
                }
                $instance = new HetznerInstance($hetznerClient);
                $hetznerClient->setInstance($instance);
                if (!("dev" == $_ENV["APP_ENV"])) {
                    break;
                }
                $instanceId = $_ENV["DEV_HETZNER_INSTANCE_ID"] ?? '';
                $instance->setIpv4PublicIp("127.0.0.1");
                $instance->setInstanceId($instanceId);
            case CloudPanelEnvironment::CLOUD_PROVIDER_VULTR:
                $environment->setCloudProvider(CloudPanelEnvironment::CLOUD_PROVIDER_VULTR);
                $vultrClient = new VultrClient();
                $apiKey = $this->configManager->get("vultr_api_key");
                if (false === is_null($apiKey)) {
                    $vultrClient->setApiKey($apiKey);
                }
                $instance = new VultrInstance($vultrClient);
                $vultrClient->setVultrInstance($instance);
                if (!("dev" == $_ENV["APP_ENV"])) {
                    break;
                }
                $instanceId = $_ENV["DEV_VULTR_INSTANCE_ID"] ?? '';
                $ipv4PublicIp = $_ENV["DEV_VULTR_IP_V4_PUBLIC"] ?? "127.0.0.1";
                $instance->setIpv4PublicIp($ipv4PublicIp);
                $instance->setInstanceId($instanceId);
            default:
                $instance = new Instance();
                break;
        }
        $instanceUid = $this->configManager->get("instance_uid");
        if (false === is_null($instanceUid)) {
            $instance->setUid($instanceUid);
        }
        $instance->setEnvironment($environment);
        $hostname = gethostname();
        $instance->setHostname($hostname);
        if ($event instanceof RequestEvent) {
            $request = $event->getRequest();
            $host = $request->getHost();
            $isDarkMode = false === is_null($request->cookies->get("theme")) && "dark" == $request->cookies->get("theme") ? true : false;
            $ipv4PublicIp = $instance->getIpv4PublicIp();
            if (true === empty($ipv4PublicIp)) {
                $ipv4PublicIp = '';
                try {
                    $ipv4PublicIp = $this->cachePool->get("ipv4_public_ip", function (ItemInterface $item) {
                        $item->expiresAfter(3600);
                        $ipv4PublicIp = null;
                        $config = ["timeout" => 5, "verify" => false, "headers" => ["User-Agent" => "CloudPanel v2"]];
                        $httpClient = new HttpClient($config);
                        $request = new Request("GET", self::IP_REQUEST_URL);
                        $response = $this->retry(function () use($httpClient, $request) {
                            $response = $httpClient->send($request);
                            return $response;
                        });
                        $responseStatusCode = $response->getStatusCode();
                        if (200 == $responseStatusCode) {
                            $ipv4PublicIp = trim((string) $response->getBody());
                        }
                        return $ipv4PublicIp;
                    });
                } catch (\Exception $e) {
                }
                $instance->setIpv4PublicIp($ipv4PublicIp);
            }
            $request->attributes->set("instance", $instance);
            $request->attributes->set("isDarkMode", $isDarkMode);
            $this->twig->addGlobal("instance", $instance);
            $this->twig->addGlobal("isDarkMode", $isDarkMode);
        } else {
            $command = $event->getCommand();
            $command->setInstance($instance);
        }
    }
    private function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
    public static function getSubscribedEvents() : array
    {
        return [KernelEvents::REQUEST => [["onKernelRequest", 25]], ConsoleEvents::COMMAND => [["onConsoleCommand", 25]]];
    }
}