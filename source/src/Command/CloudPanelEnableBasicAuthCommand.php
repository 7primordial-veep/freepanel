<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Security\Admin\BasicAuth as AdminBasicAuth;
use App\Entity\Manager\UserManager as UserEntityManager;
class CloudPanelEnableBasicAuthCommand extends BaseCommand
{
    private UserEntityManager $userEntityManager;
    public function __construct(UserEntityManager $userEntityManager)
    {
        $this->userEntityManager = $userEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("cloudpanel:enable:basic-auth");
        $this->setDescription("clpctl cloudpanel:enable:basic-auth --userName=john.doe --password='password123'");
        $this->addOption("userName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("password", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $userName = trim($input->getOption("userName"));
            $password = trim($input->getOption("password"));
            if (!(false === empty($userName) && false === empty($password))) {
                throw new \Exception("User Name and Password cannot be empty.");
            }
            $adminBasicAuth = new AdminBasicAuth();
            $adminBasicAuth->enable($userName, $password);
            $output->writeln("<info>Basic Auth has been enabled.</info>");
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}