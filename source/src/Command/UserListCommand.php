<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use App\Entity\Manager\UserManager as UserEntityManager;
use App\Command\Command as BaseCommand;
use App\Entity\User;
class UserListCommand extends BaseCommand
{
    private UserEntityManager $userEntityManager;
    public function __construct(UserEntityManager $userEntityManager)
    {
        $this->userEntityManager = $userEntityManager;
        parent::__construct();
    }
    protected function configure()
    {
        $this->setName("user:list");
        $this->setDescription("clpctl user:list");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $users = $this->userEntityManager->findAll([], ["userName" => "asc"]);
            if (count($users)) {
                $tableRows = [];
                $table = new Table($output);
                $table->setHeaders(["User Name", "First Name", "Last Name", "E-Mail", "Role", "Status"]);
                foreach ($users as $user) {
                    $role = $user->getRole();
                    switch ($role) {
                        case User::ROLE_ADMIN:
                            $role = "Admin";
                            break;
                        case User::ROLE_SITE_MANAGER:
                            $role = "Site Manager";
                            break;
                        case User::ROLE_USER:
                            $role = "User";
                            break;
                    }
                    $tableRows[] = ["User Name" => $user->getUserName(), "First Name" => $user->getFirstName(), "Last Name" => $user->getLastName(), "E-Mail" => $user->getEmail(), "Role" => $role, "Status" => true === $user->getStatus() ? "Active" : "Not Active"];
                }
                $table->setRows($tableRows);
                $table->render();
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}