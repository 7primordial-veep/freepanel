<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\DatabaseManager as DatabaseEntityManager;
use App\Entity\Manager\DatabaseUserManager as DatabaseUserEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Database\Manager as DatabaseManager;
class DatabaseDeleteCommand extends BaseCommand
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
        $this->setName("db:delete");
        $this->setDescription("clpctl db:delete --databaseName=my-database");
        $this->addOption("databaseName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("force", "f", InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $databaseName = trim($input->getOption("databaseName"));
            $force = $input->getOption("force") === true;
            $databaseEntity = $this->databaseEntityManager->findOneByName($databaseName);
            if (true === is_null($databaseEntity)) {
                throw new \Exception(sprintf("Database \"%s\" does not exist.", $databaseName));
            }
            if (false === $force) {
                $helper = $this->getHelper("question");
                $question = new ConfirmationQuestion(sprintf("<info>Confirm to delete the database: %s</info> <comment>(yes/no):</comment> ", $databaseName), false, "/^(yes)/i");
                $answer = $helper->ask($input, $output, $question);
                if (false === $answer) {
                    return BaseCommand::SUCCESS;
                }
            }
            $siteEntity = $databaseEntity->getSite();
            $databaseServerEntity = $databaseEntity->getDatabaseServer();
            $databaseManager = new DatabaseManager($databaseServerEntity);
            $databaseManager->deleteDatabase($databaseEntity, true);
            $siteEntity->removeDatabase($databaseEntity);
            $this->siteEntityManager->updateEntity($siteEntity);
            $output->writeln(sprintf("<info>Database</info> <comment>%s</comment> <info>has been deleted.</info>", $databaseName));
            return BaseCommand::SUCCESS;
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