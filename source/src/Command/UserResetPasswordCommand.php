<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\UserManager as UserEntityManager;
class UserResetPasswordCommand extends BaseCommand
{
    private UserEntityManager $userEntityManager;
    public function __construct(UserEntityManager $userEntityManager)
    {
        $this->userEntityManager = $userEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("user:reset:password");
        $this->setDescription("clpctl user:reset:password --userName='john.doe' --password='!newPassword!'");
        $this->addOption("userName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("password", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $userName = trim($input->getOption("userName"));
            $password = trim($input->getOption("password"));
            $user = $this->userEntityManager->findOneByUserName($userName);
            if (!(false === is_null($user))) {
                throw new \Exception(sprintf("User \"%s\" does not exist.", $userName));
            }
            $user->setPlainPassword($password);
            $this->userEntityManager->updateUser($user, true, true);
            $output->writeln(sprintf("<info>Password for \"%s\" has been reset.</info>", $userName));
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}