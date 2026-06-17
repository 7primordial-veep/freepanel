<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use App\Entity\Manager\UserManager as UserEntityManager;
use App\Command\Command as BaseCommand;
class UserDeleteCommand extends BaseCommand
{
    private UserEntityManager $userEntityManager;
    public function __construct(UserEntityManager $userEntityManager)
    {
        $this->userEntityManager = $userEntityManager;
        parent::__construct();
    }
    protected function configure()
    {
        $this->setName("user:delete");
        $this->setDescription("clpctl user:delete --userName='john.doe'");
        $this->addOption("userName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("force", null, InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $userName = trim($input->getOption("userName"));
            $force = $input->getOption("force") === true;
            if (false === $force) {
                $helper = $this->getHelper("question");
                $question = new ConfirmationQuestion(sprintf("<info>Confirm to delete the user: %s</info> <comment>(yes/no):</comment> ", $userName), false, "/^(yes)/i");
                $answer = $helper->ask($input, $output, $question);
                if (false === $answer) {
                    return BaseCommand::SUCCESS;
                }
            }
            $userEntity = $this->userEntityManager->findOneByUserName($userName);
            if (true === is_null($userEntity)) {
                throw new \Exception(sprintf("User \"%s\" does not exist.", $userName));
            }
            $this->userEntityManager->deleteUser($userEntity);
            $output->writeln(sprintf("<info>User</info> <comment>%s</comment> <info>has been deleted.</info>", $userName));
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}