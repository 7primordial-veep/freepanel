<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\DatabaseManager as DatabaseEntityManager;
use App\Entity\Manager\DatabaseUserManager as DatabaseUserEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Entity\DatabaseUser as DatabaseUserEntity;
use App\Database\Manager as DatabaseManager;
use App\Service\Crypto;
class DatabaseAddCommand extends BaseCommand
{
    private SiteEntityManager $siteEntityManager;
    private DatabaseEntityManager $databaseEntityManager;
    private DatabaseUserEntityManager $databaseUserEntityManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    private ValidatorInterface $validator;
    public function __construct(SiteEntityManager $siteEntityManager, DatabaseEntityManager $databaseEntityManager, DatabaseUserEntityManager $databaseUserEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, ValidatorInterface $validator)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->databaseEntityManager = $databaseEntityManager;
        $this->databaseUserEntityManager = $databaseUserEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->validator = $validator;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("db:add");
        $this->setDescription("clpctl db:add --domainName=www.domain.com --databaseName=my-database --databaseUserName=john --databaseUserPassword='!secretPassword!'");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("databaseName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("databaseUserName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("databaseUserPassword", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $domainName = trim($input->getOption("domainName"));
            $databaseName = trim($input->getOption("databaseName"));
            $databaseUserName = trim($input->getOption("databaseUserName"));
            $databaseUserPassword = trim($input->getOption("databaseUserPassword"));
            $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
            if (true === is_null($siteEntity)) {
                throw new \Exception(sprintf("DomainName \"%s\" does not exist.", $domainName));
            }
            $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
            if (false === is_null($activeDatabaseServerEntity)) {
                $databaseEntity = $this->databaseEntityManager->createEntity();
                $databaseEntity->setDatabaseServer($activeDatabaseServerEntity);
                $databaseEntity->setName($databaseName);
                $databaseEntity->setSite($siteEntity);
                $databaseUserEntity = $this->databaseUserEntityManager->createEntity();
                $databaseUserEntity->setUserName($databaseUserName);
                $databaseUserEntity->setPassword(Crypto::encrypt($databaseUserPassword));
                $databaseUserEntity->setPermissions(DatabaseUserEntity::PERMISSIONS_READ_WRITE);
                $databaseUserEntity->setDatabase($databaseEntity);
                $databaseEntity->addUser($databaseUserEntity);
                $siteEntity->addDatabase($databaseEntity);
                $databaseConstraints = $this->validator->validate($databaseEntity);
                $databaseUserConstraints = $this->validator->validate($databaseUserEntity);
                if (!(0 == count($databaseConstraints) && 0 == count($databaseUserConstraints))) {
                    $constraints = new ConstraintViolationList();
                    $constraints->addAll($databaseConstraints);
                    $constraints->addAll($databaseUserConstraints);
                    return $this->renderConstraints($constraints, $output);
                }
                $databaseManager = new DatabaseManager($activeDatabaseServerEntity);
                $databaseManager->createDatabase($databaseEntity);
                $databaseManager->createUser($databaseUserEntity);
                $this->siteEntityManager->updateEntity($siteEntity);
                $output->writeln(sprintf("<info>Database</info> <comment>%s</comment> <info>has been added.</info>", $databaseName));
                return BaseCommand::SUCCESS;
            }
            return BaseCommand::FAILURE;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    protected function prepareConstraints(ConstraintViolationList $constraints) : void
    {
        $this->changePropertyPath("name", "databaseName", $constraints);
        $this->changePropertyPath("userName", "databaseUserName", $constraints);
    }
}