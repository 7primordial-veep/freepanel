<?php

namespace App\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Command\ListCommand;
class Application extends BaseApplication
{
    const APPLICATION_NAME = "CloudPanel CLI";
    const DEFAULT_COMMAND_NAME = "app:list";
    const SYSTEM_USER_ROOT = "root";
    const SYSTEM_USER_CLP = "clp";
    private KernelInterface $kernel;
    private bool $commandsRegistered = false;
    private array $registrationErrors = [];
    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
        parent::__construct($this->kernel);
        $this->setDefaultCommand(self::DEFAULT_COMMAND_NAME);
    }
    public function add(Command $command) : ?Command
    {
        $this->registerCommands();
        return parent::add($command);
    }
    protected function registerCommands() : void
    {
        if ($this->commandsRegistered) {
            return;
        }
        $this->commandsRegistered = true;
        $this->kernel->boot();
        $this->initCommandLoader();
    }
    private function renderRegistrationErrors(InputInterface $input, OutputInterface $output) : void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        (new SymfonyStyle($input, $output))->warning("Some commands could not be registered:");
        foreach ($this->registrationErrors as $error) {
            $this->doRenderThrowable($error, $output);
        }
    }
    private function initCommandLoader() : void
    {
        $commandLoader = $this->getCommandLoader();
        $this->setCommandLoader($commandLoader);
    }
    public function getCommandLoader() : FactoryCommandLoader
    {
        if ("dev" == $_ENV["APP_ENV"]) {
            $container = $this->getContainer();
            $rootCommands = $this->getCommands("root");
            $clpCommands = $this->getCommands("clp");
            $commands = array_merge($rootCommands, $clpCommands);
            $commands["test:test"] = function () use($container) {
                return $container->get("App\\Command\\TestCommand");
            };
            $commands["varnish-cache:purge"] = function () use($container) {
                return $container->get("App\\Command\\VarnishCachePurgeCommand");
            };
        } else {
            $systemUserName = $_SERVER["SUDO_USER"] ?? '';
            $commands = $this->getCommands($systemUserName);
        }
        $commandLoader = new FactoryCommandLoader($commands);
        return $commandLoader;
    }
    public function getCommands($systemUserName) : array
    {
        $container = $this->getContainer();
        switch ($systemUserName) {
            case self::SYSTEM_USER_ROOT:
                $commands = ["cloudpanel:enable:basic-auth" => function () use($container) {
                    return $container->get("App\\Command\\CloudPanelEnableBasicAuthCommand");
                }, "cloudpanel:disable:basic-auth" => function () use($container) {
                    return $container->get("App\\Command\\CloudPanelDisableBasicAuthCommand");
                }, "cloudpanel:set:release-channel" => function () use($container) {
                    return $container->get("App\\Command\\CloudPanelSetReleaseChannelCommand");
                }, "cloudflare:update:ips" => function () use($container) {
                    return $container->get("App\\Command\\CloudflareUpdateIpsCommand");
                }, "cloudflare:dns:create" => function () use($container) {
                    return $container->get("App\\Command\\CloudflareDnsCreateCommand");
                }, "db:show:master-credentials" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseShowMasterCredentialsCommand");
                }, "db:add" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseAddCommand");
                }, "db:export" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseExportCommand");
                }, "db:import" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseImportCommand");
                }, "db:delete" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseDeleteCommand");
                }, "lets-encrypt:install:certificate" => function () use($container) {
                    return $container->get("App\\Command\\LetsEncryptInstallCertificateCommand,
            LetsEncryptInstallWildcardCertificateCommand");
                }, "site:add:nodejs" => function () use($container) {
                    return $container->get("App\\Command\\SiteAddNodejsCommand");
                }, "site:add:static" => function () use($container) {
                    return $container->get("App\\Command\\SiteAddStaticCommand");
                }, "site:add:php" => function () use($container) {
                    return $container->get("App\\Command\\SiteAddPhpCommand");
                }, "site:add:python" => function () use($container) {
                    return $container->get("App\\Command\\SiteAddPythonCommand");
                }, "site:add:reverse-proxy" => function () use($container) {
                    return $container->get("App\\Command\\SiteAddReverseProxyCommand");
                }, "site:delete" => function () use($container) {
                    return $container->get("App\\Command\\SiteDeleteCommand");
                }, "site:clone" => function () use($container) {
                    return $container->get("App\\Command\\SiteCloneCommand");
                }, "site:install:certificate" => function () use($container) {
                    return $container->get("App\\Command\\SiteInstallCertificateCommand");
                }, "system:permissions:reset" => function () use($container) {
                    return $container->get("App\\Command\\SystemPermissionsResetCommand");
                }, "user:add" => function () use($container) {
                    return $container->get("App\\Command\\UserAddCommand");
                }, "user:delete" => function () use($container) {
                    return $container->get("App\\Command\\UserDeleteCommand");
                }, "user:list" => function () use($container) {
                    return $container->get("App\\Command\\UserListCommand");
                }, "user:reset:password" => function () use($container) {
                    return $container->get("App\\Command\\UserResetPasswordCommand");
                }, "user:disable:mfa" => function () use($container) {
                    return $container->get("App\\Command\\UserDisableMfaCommand");
                }, "vhost-templates:import" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplatesImportCommand");
                }, "vhost-templates:list" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplatesListCommand");
                }, "vhost-template:add" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplateAddCommand");
                }, "vhost-template:delete" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplateDeleteCommand");
                }, "vhost-template:view" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplateViewCommand");
                }, "marketplace:seed" => function () use($container) {
                    return $container->get("App\\Command\\MarketplaceSeedCommand");
                }];
                break;
            case self::SYSTEM_USER_CLP:
                $commands = ["announcement:check" => function () use($container) {
                    return $container->get("App\\Command\\AnnouncementCheckCommand");
                }, "app:clean-up:sessions" => function () use($container) {
                    return $container->get("App\\Command\\AppCleanupSessionsCommand");
                }, "app:get:config-value" => function () use($container) {
                    return $container->get("App\\Command\\AppGetConfigValueCommand");
                }, "app:set:config-value" => function () use($container) {
                    return $container->get("App\\Command\\AppSetConfigValueCommand");
                }, "cloudflare:update:ips" => function () use($container) {
                    return $container->get("App\\Command\\CloudflareUpdateIpsCommand");
                }, "cloudpanel:delete:sites" => function () use($container) {
                    return $container->get("App\\Command\\CloudPanelDeleteSitesCommand");
                }, "monitoring:data:clean" => function () use($container) {
                    return $container->get("App\\Command\\MonitoringDataCleanCommand");
                }, "db:backup" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseBackupCommand");
                }, "aws:image:create" => function () use($container) {
                    return $container->get("App\\Command\\AwsImageCreateCommand");
                }, "do:snapshot:create" => function () use($container) {
                    return $container->get("App\\Command\\DoSnapshotCreateCommand");
                }, "gce:snapshot:create" => function () use($container) {
                    return $container->get("App\\Command\\GceSnapshotCreateCommand");
                }, "vultr:snapshot:create" => function () use($container) {
                    return $container->get("App\\Command\\VultrSnapshotCreateCommand");
                }, "hetzner:snapshot:create" => function () use($container) {
                    return $container->get("App\\Command\\HetznerSnapshotCreateCommand");
                }, "lets-encrypt:renew:certificates" => function () use($container) {
                    return $container->get("App\\Command\\LetsEncryptRenewCertificatesCommand");
                }, "lets-encrypt:renew:custom-domain:certificate" => function () use($container) {
                    return $container->get("App\\Command\\LetsEncryptRenewCustomDomainCertificateCommand");
                }, "site:delete" => function () use($container) {
                    return $container->get("App\\Command\\SiteDeleteCommand");
                }, "vhost-templates:import" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplatesImportCommand");
                }, "vhost-templates:list" => function () use($container) {
                    return $container->get("App\\Command\\VhostTemplatesListCommand");
                }, "remote-backup:create" => function () use($container) {
                    return $container->get("App\\Command\\RemoteBackupCreateCommand");
                }, "remote-backup:test-restore" => function () use($container) {
                    return $container->get("App\\Command\\RemoteBackupTestRestoreCommand");
                }, "quota:enforce" => function () use($container) {
                    return $container->get("App\\Command\\QuotaEnforceCommand");
                }, "test:test" => function () use($container) {
                    return $container->get("App\\Command\\TestCommand");
                }];
                break;
            default:
                $commands = ["db:export" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseExportCommand");
                }, "db:import" => function () use($container) {
                    return $container->get("App\\Command\\DatabaseImportCommand");
                }, "system:permissions:reset" => function () use($container) {
                    return $container->get("App\\Command\\SystemPermissionsResetCommand");
                }, "varnish-cache:purge" => function () use($container) {
                    return $container->get("App\\Command\\VarnishCachePurgeCommand");
                }];
                break;
        }
        return $commands;
    }
    public function doRun(InputInterface $input, OutputInterface $output) : int
    {
        $this->registerCommands();
        $this->setApplicationName();
        return parent::doRun($input, $output);
    }
    private function setApplicationName() : void
    {
        $this->setName(self::APPLICATION_NAME);
    }
    protected function getDefaultCommands() : array
    {
        $listCommand = new ListCommand();
        return [$listCommand];
    }
    public function getContainer() : ContainerInterface
    {
        return $this->kernel->getContainer();
    }
}