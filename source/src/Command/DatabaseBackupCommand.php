<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Database as DatabaseEntity;
use App\System\CommandExecutor;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\DatabaseManager as DatabaseEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\System\Command\DeleteOldFilesRecursiveCommand;
use App\System\Command\FindChmodCommand;
use App\System\Command\ChownCommand;
use App\Database\Exporter as DatabaseExporter;
class DatabaseBackupCommand extends BaseCommand
{
    private const RETENTION_PERIOD = 7;
    private SiteEntityManager $siteEntityManager;
    private DatabaseEntityManager $databaseEntityManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    private CommandExecutor $commandExecutor;
    private ValidatorInterface $validator;
    private int $retentionPeriod = 0;
    public function __construct(SiteEntityManager $siteEntityManager, DatabaseEntityManager $databaseEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, ValidatorInterface $validator)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->databaseEntityManager = $databaseEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->commandExecutor = new CommandExecutor();
        $this->validator = $validator;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("db:backup");
        $this->setDescription("clpctl db:backup --ignoreDatabases='db1,db2' --retentionPeriod=7");
        $this->addOption("ignoreDatabases", null, InputOption::VALUE_OPTIONAL);
        $this->addOption("retentionPeriod", null, InputOption::VALUE_OPTIONAL, '', self::RETENTION_PERIOD);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $ignoredDatabases = explode(",", (string) $input->getOption("ignoreDatabases"));
            $this->retentionPeriod = (int) $input->getOption("retentionPeriod");
            $siteEntities = $this->siteEntityManager->findAll();
            if (count($siteEntities)) {
                foreach ($siteEntities as $siteEntity) {
                    $databaseEntities = $siteEntity->getDatabases();
                    if (!count($databaseEntities)) {
                        continue;
                    }
                    foreach ($databaseEntities as $databaseEntity) {
                        $databaseName = $databaseEntity->getName();
                        if (!(false === in_array($databaseName, $ignoredDatabases))) {
                            continue;
                        }
                        $this->createDatabaseDump($databaseEntity);
                    }
                }
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function createDatabaseDump(DatabaseEntity $databaseEntity) : void
    {
        try {
            $dateTime = new \DateTime("now", new \DateTimeZone("UTC"));
            $siteEntity = $databaseEntity->getSite();
            $siteUser = $siteEntity->getUser();
            $databaseName = $databaseEntity->getName();
            $backupDirectory = sprintf("/home/%s/backups/databases/", $siteUser);
            $outputDirectory = sprintf("%s/%s/%s/", rtrim($backupDirectory, "/"), $databaseName, $dateTime->format("Y-m-d"));
            $outputFile = sprintf("%s/%s", rtrim($outputDirectory, "/"), sprintf("%s_%s.sql.gz", $databaseName, $dateTime->getTimestamp()));
            $databaseExporter = new DatabaseExporter($databaseEntity);
            $databaseExporter->setFile($outputFile);
            $databaseExporter->createOutputDirectory();
            $databaseExporter->export();
            $this->resetPermissions($databaseEntity);
            $this->cleanUpBackups($databaseEntity);
        } catch (\Exception $e) {
            $logger = $this->getLogger();
            $logger->exception($e);
        }
    }
    private function resetPermissions(DatabaseEntity $databaseEntity)
    {
        $siteEntity = $databaseEntity->getSite();
        $siteUser = $siteEntity->getUser();
        $backupDirectory = sprintf("/home/%s/backups/databases/", $siteUser);
        $backupDirectoryChownCommand = new ChownCommand();
        $backupDirectoryChownCommand->setFile($backupDirectory);
        $backupDirectoryChownCommand->setRecursive(true);
        $backupDirectoryChownCommand->setUser($siteUser);
        $backupDirectoryChownCommand->setGroup($siteUser);
        $backupDirectoryChmodCommand = new FindChmodCommand();
        $backupDirectoryChmodCommand->setFile($backupDirectory);
        $backupDirectoryChmodCommand->setDirectoryChmod(750);
        $backupDirectoryChmodCommand->setFileChmod(760);
        $this->commandExecutor->execute($backupDirectoryChownCommand);
        $this->commandExecutor->execute($backupDirectoryChmodCommand);
    }
    private function cleanUpBackups(DatabaseEntity $databaseEntity)
    {
        $siteEntity = $databaseEntity->getSite();
        $siteUser = $siteEntity->getUser();
        $databaseBackupDirectory = sprintf("/home/%s/backups/databases/", $siteUser);
        $deleteOldBackupsCommand = new DeleteOldFilesRecursiveCommand();
        $deleteOldBackupsCommand->setDirectory($databaseBackupDirectory);
        $deleteOldBackupsCommand->setRetentionPeriod($this->retentionPeriod);
        $this->commandExecutor->execute($deleteOldBackupsCommand, 360);
    }
}