<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Entity\Manager\UserManager as UserEntityManager;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\TimezoneManager as TimeZoneEntityManager;
use App\Command\Command as BaseCommand;
use App\Entity\User;
class UserAddCommand extends BaseCommand
{
    private UserEntityManager $userEntityManager;
    private SiteEntityManager $siteEntityManager;
    private TimezoneEntityManager $timezoneEntityManager;
    private ValidatorInterface $validator;
    public function __construct(UserEntityManager $userEntityManager, SiteEntityManager $siteEntityManager, TimeZoneEntityManager $timezoneEntityManager, ValidatorInterface $validator)
    {
        $this->userEntityManager = $userEntityManager;
        $this->siteEntityManager = $siteEntityManager;
        $this->timezoneEntityManager = $timezoneEntityManager;
        $this->validator = $validator;
        parent::__construct();
    }
    protected function configure()
    {
        $this->setName("user:add");
        $this->setDescription("clpctl user:add --userName='john.doe' --email='john.doe@domain.com' --firstName='John' --lastName='Doe' --password='!password!' --role='user' --sites='domain.com,domain.io' --timezone='Europe/Berlin' --status='1'");
        $this->addOption("userName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("email", null, InputOption::VALUE_REQUIRED);
        $this->addOption("firstName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("lastName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("password", null, InputOption::VALUE_REQUIRED);
        $this->addOption("role", null, InputOption::VALUE_OPTIONAL, "Role", "User");
        $this->addOption("sites", null, InputOption::VALUE_OPTIONAL, "Sites");
        $this->addOption("timezone", null, InputOption::VALUE_OPTIONAL, "Timezone", User::DEFAULT_TIMEZONE);
        $this->addOption("status", null, InputOption::VALUE_OPTIONAL, "Status", User::STATUS_ACTIVE);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $userName = mb_strtolower(trim($input->getOption("userName")));
            $email = mb_strtolower(trim($input->getOption("email")));
            $firstName = trim($input->getOption("firstName"));
            $lastName = trim($input->getOption("lastName"));
            $password = trim($input->getOption("password"));
            $status = (bool) $input->getOption("status");
            $role = str_replace(["-"], ["_"], trim($input->getOption("role")));
            $role = sprintf("ROLE_%s", strtoupper($role));
            $sites = array_map("trim", array_filter(explode(",", trim($input->getOption("sites")))));
            $timezoneName = trim($input->getOption("timezone"));
            $timezone = $this->timezoneEntityManager->findOneByName($timezoneName);
            $userEntity = $this->userEntityManager->createEntity();
            if (true === is_null($timezone)) {
                throw new \Exception(sprintf("Timezone \"%s\" is not a valid timezone", $timezoneName));
            }
            if (false === in_array($role, [USER::ROLE_USER, User::ROLE_SITE_MANAGER, USER::ROLE_ADMIN])) {
                throw new \Exception(sprintf("Role \"%s\" is not valid, valid roles: %s", $role, implode(", ", [USER::ROLE_USER, User::ROLE_SITE_MANAGER, USER::ROLE_ADMIN])));
            }
            if (USER::ROLE_USER == $role) {
                if (true === empty($sites)) {
                    throw new \Exception("Sites cannot be empty.");
                }
                foreach ($sites as $domainName) {
                    $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
                    if (true === is_null($siteEntity)) {
                        throw new \Exception(sprintf("Site \"%s\" does not exist.", $domainName));
                    }
                    $userEntity->addSite($siteEntity);
                }
            }
            $userEntity->setUserName($userName);
            $userEntity->setEmail($email);
            $userEntity->setFirstName($firstName);
            $userEntity->setLastname($lastName);
            $userEntity->setPassword($password);
            $userEntity->setPlainPassword($password);
            $userEntity->setStatus($status);
            $userEntity->setRole($role);
            $userEntity->setTimezone($timezone);
            $errors = $this->validator->validate($userEntity);
            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $errorMessage = sprintf("%s: %s", $error->getPropertyPath(), $error->getMessage());
                    $output->writeln(sprintf("<error>%s</error>", $errorMessage));
                }
                return BaseCommand::FAILURE;
            } else {
                $this->userEntityManager->updateUser($userEntity);
                $output->writeln(sprintf("<info>User \"%s\" has been added.</info>", $userName));
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}