<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\DatabaseManager as DatabaseEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Entity\Manager\SshUserManager as SshUserEntityManager;
use App\Database\Exporter as DatabaseExporter;
class DatabaseExportCommand extends BaseCommand
{
    const SYSTEM_USER_ROOT = "root";
    private SiteEntityManager $siteEntityManager;
    private DatabaseEntityManager $databaseEntityManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    private SshUserEntityManager $sshUserEntityManager;
    public function __construct(SiteEntityManager $siteEntityManager, DatabaseEntityManager $databaseEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, SshUserEntityManager $sshUserEntityManager)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->databaseEntityManager = $databaseEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->sshUserEntityManager = $sshUserEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("db:export");
        $this->setDescription("clpctl db:export --databaseName=my-database --file=dump.sql.gz");
        $this->addOption("databaseName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("file", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $databaseName = $input->getOption("databaseName");
            $file = $input->getOption("file");
            $systemUserName = $_SERVER["SUDO_USER"] ?? '';
            if ("dev" == $_ENV["APP_ENV"]) {
                $systemUserName = self::SYSTEM_USER_ROOT;
            }
            if (false === str_contains($file, "/")) {
                $file = sprintf("%s/%s", rtrim(getcwd(), "/"), $file);
            }
            $databaseEntity = $this->databaseEntityManager->findOneByName($databaseName);
            if (!(false === is_null($databaseEntity))) {
                throw new \Exception(sprintf("Database \"%s\" does not exist.", $databaseName));
            }
            $siteEntity = $this->siteEntityManager->findOneByUser($systemUserName);
            if (true === is_null($siteEntity)) {
                $sshUserEntity = $this->sshUserEntityManager->findOneByUserName($systemUserName);
                if (false === is_null($sshUserEntity)) {
                    $siteEntity = $sshUserEntity->getSite();
                }
            }
            $siteUser = null;
            $userBelongsToSite = false;
            if (false === is_null($siteEntity)) {
                $siteUser = $siteEntity->getUser();
                $userBelongsToSite = $siteUser === $databaseEntity->getSite()->getUser();
            }
            $isSuperUser = false;
            if (self::SYSTEM_USER_ROOT == $systemUserName) {
                $isSuperUser = true;
                $siteUser = self::SYSTEM_USER_ROOT;
            }
            $userIsAllowedToExport = true === $isSuperUser || true === $userBelongsToSite;
            if (true === $userIsAllowedToExport && false === is_null($siteUser)) {
                $databaseExporter = new DatabaseExporter($databaseEntity);
                $databaseExporter->setRunAsUser($siteUser);
                $databaseExporter->setFile($file);
                $databaseExporter->export();
                $output->writeln(sprintf("<info>Database</info> <comment>%s</comment> <info>has been exported.</info>", $databaseName));
                return BaseCommand::SUCCESS;
            }
            return BaseCommand::FAILURE;
        } catch (\Exception $e) {
            $errorMessage = $this->maskString($e->getMessage());
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}