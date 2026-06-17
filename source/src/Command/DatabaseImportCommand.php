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
use App\Database\Importer as DatabaseImporter;
use App\System\Command\CheckIfFileExistsCommand;
use App\System\CommandExecutor;
class DatabaseImportCommand extends BaseCommand
{
    const SYSTEM_USER_ROOT = "root";
    private SiteEntityManager $siteEntityManager;
    private DatabaseEntityManager $databaseEntityManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    private SshUserEntityManager $sshUserEntityManager;
    private CommandExecutor $commandExecutor;
    public function __construct(SiteEntityManager $siteEntityManager, DatabaseEntityManager $databaseEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, SshUserEntityManager $sshUserEntityManager)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->databaseEntityManager = $databaseEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->sshUserEntityManager = $sshUserEntityManager;
        $this->commandExecutor = new CommandExecutor();
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("db:import");
        $this->setDescription("clpctl db:import --databaseName=my-database --file=dump.sql.gz");
        $this->addOption("databaseName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("file", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $databaseName = $input->getOption("databaseName");
            $importFile = $input->getOption("file");
            $systemUserName = $_SERVER["SUDO_USER"] ?? '';
            if ("dev" == $_ENV["APP_ENV"]) {
                $systemUserName = self::SYSTEM_USER_ROOT;
            }
            if (false === str_contains($importFile, "/")) {
                $importFile = sprintf("%s/%s", rtrim(getcwd(), "/"), $importFile);
            }
            $this->checkIfFileExists($importFile);
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
            $userIsAllowedToImport = true === $isSuperUser || true === $userBelongsToSite;
            if (true === $userIsAllowedToImport && false === is_null($siteUser)) {
                $databaseImporter = new DatabaseImporter($databaseEntity);
                $databaseImporter->import($importFile);
                $output->writeln(sprintf("<info>Import into database</info> <comment>%s</comment> <info>was successful.</info>", $databaseName));
                return BaseCommand::SUCCESS;
            }
            return BaseCommand::FAILURE;
        } catch (\Exception $e) {
            $errorMessage = $this->maskString($e->getMessage());
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function checkIfFileExists(string $importFile)
    {
        try {
            $checkIfFileExistsCommand = new CheckIfFileExistsCommand();
            $checkIfFileExistsCommand->setFile($importFile);
            $this->commandExecutor->execute($checkIfFileExistsCommand);
        } catch (\Exception $e) {
            throw new \Exception(sprintf("File \"%s\" does not exist.", $importFile));
        }
    }
}