<?php

namespace App\Site\Creator;

use App\Site\Creator as SiteCreator;
use App\Site\Nginx\Vhost\PhpTemplate;
use App\Site\PhpFpm\Pool;
use App\Site\PhpFpm\PoolBuilder;
use App\Site\PhpFpm\PoolReader;
use App\System\Command\CreateDirectoryCommand;
use App\System\Command\WriteFileCommand;
use App\System\Command\CopyFileCommand;
class PhpSite extends SiteCreator
{
    private const INDEX_PHP_TEMPLATE = "<?php\n\necho 'Hello World :-)';";
    public function createPhpFpmPool() : void
    {
        $siteUser = $this->site->getUser();
        $phpSettings = $this->site->getPhpSettings();
        $domainName = $this->site->getDomainName();
        $phpVersion = $phpSettings->getPhpVersion();
        $poolDirectory = sprintf("/etc/php/%s/fpm/pool.d/", $phpVersion);
        $poolReader = new PoolReader($poolDirectory);
        $pools = $poolReader->getPools();
        usort($pools, function ($a, $b) {
            return $a->getPort() < $b->getPort();
        });
        $latestPool = array_shift($pools);
        $poolPort = (true === is_null($latestPool) ? 9000 : $latestPool->getPort()) + 1;
        $pool = new Pool();
        $pool->setName($domainName);
        $pool->setUser($siteUser);
        $pool->setGroup($siteUser);
        $pool->setPort($poolPort);
        $phpSettings->setPoolPort($poolPort);
        $poolBuilder = new PoolBuilder();
        $poolContent = $poolBuilder->create($pool);
        $poolFile = sprintf("/etc/php/%s/fpm/pool.d/%s.conf", $phpVersion, $domainName);
        $writePoolFileCommand = new WriteFileCommand();
        $writePoolFileCommand->setFile($poolFile);
        $writePoolFileCommand->setContent($poolContent);
        $this->commandExecutor->execute($writePoolFileCommand);
    }
    public function createVarnishCacheStructure(array $varnishCacheSettings)
    {
        $varnishController = $varnishCacheSettings["controller"] ?? null;
        if (true === isset($varnishCacheSettings["controller"])) {
            unset($varnishCacheSettings["controller"]);
        }
        $varnishControllerSourceFile = realpath(dirname(__FILE__) . sprintf("/../../../resources/varnish-cache/controller/%s/controller.php", $varnishController));
        $siteUser = $this->site->getUser();
        $varnishCacheDirectory = sprintf("/home/%s/.varnish-cache/", $siteUser);
        $varnishCacheLogDirectory = sprintf("/home/%s/logs/varnish-cache/", $siteUser);
        $varnishCacheSettingsFile = sprintf("%s/settings.json", rtrim($varnishCacheDirectory, "/"));
        $varnishCachePurgeLogfile = sprintf("%s/purge.log", rtrim($varnishCacheLogDirectory, "/"));
        $varnishCacheControllerFile = sprintf("%s/controller.php", rtrim($varnishCacheDirectory, "/"));
        $createVarnishCacheDirectoryCommand = new CreateDirectoryCommand();
        $createVarnishCacheDirectoryCommand->setDirectory($varnishCacheDirectory);
        $writeVarnishCacheSettingsFileCommand = new WriteFileCommand();
        $writeVarnishCacheSettingsFileCommand->setFile($varnishCacheSettingsFile);
        $writeVarnishCacheSettingsFileCommand->setContent(json_encode($varnishCacheSettings, JSON_PRETTY_PRINT));
        $copyVarnishControllerFileCommand = new CopyFileCommand();
        $copyVarnishControllerFileCommand->setSourceFile($varnishControllerSourceFile);
        $copyVarnishControllerFileCommand->setDestinationFile($varnishCacheControllerFile);
        $createVarnishCacheLogDirectoryCommand = new CreateDirectoryCommand();
        $createVarnishCacheLogDirectoryCommand->setDirectory($varnishCacheLogDirectory);
        $writeVarnishCachePurgeLogfileCommand = new WriteFileCommand();
        $writeVarnishCachePurgeLogfileCommand->setFile($varnishCachePurgeLogfile);
        $writeVarnishCachePurgeLogfileCommand->setContent('');
        $this->commandExecutor->execute($createVarnishCacheDirectoryCommand);
        $this->commandExecutor->execute($writeVarnishCacheSettingsFileCommand);
        $this->commandExecutor->execute($copyVarnishControllerFileCommand);
        $this->commandExecutor->execute($createVarnishCacheLogDirectoryCommand);
        $this->commandExecutor->execute($writeVarnishCachePurgeLogfileCommand);
    }
    public function createIndexPhp() : void
    {
        $rootDirectory = $this->getRootDirectory();
        $indexPhpFile = sprintf("%s/index.php", rtrim($rootDirectory, "/"));
        $writeIndexPhpFileCommand = new WriteFileCommand();
        $writeIndexPhpFileCommand->setFile($indexPhpFile);
        $writeIndexPhpFileCommand->setContent(self::INDEX_PHP_TEMPLATE);
        $this->commandExecutor->execute($writeIndexPhpFileCommand);
    }
    public function reloadPhpFpmService()
    {
        $phpSettings = $this->site->getPhpSettings();
        $phpVersion = $phpSettings->getPhpVersion();
        $serviceName = sprintf("php%s-fpm", $phpVersion);
        $this->reloadService($serviceName);
    }
}