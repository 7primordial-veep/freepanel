<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\UserManager as UserEntityManager;
class UserDisableMfaCommand extends BaseCommand
{
    private UserEntityManager $userEntityManager;
    public function __construct(UserEntityManager $userEntityManager)
    {
        $this->userEntityManager = $userEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("user:disable:mfa");
        $this->setDescription("clpctl user:disable:mfa --userName='john.doe'");
        $this->addOption("userName", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $userName = trim($input->getOption("userName"));
            $user = $this->userEntityManager->findOneByUserName($userName);
            if (!(false === is_null($user))) {
                throw new \Exception(sprintf("User \"%s\" does not exist", $userName));
            }
            $user->setMfa(false);
            $this->userEntityManager->updateUser($user);
            $output->writeln(sprintf("<info>Two-Factor authentication for \"%s\" has been disabled.</info>", $userName));
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}