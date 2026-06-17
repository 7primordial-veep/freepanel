<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\SshUserManager as SshUserEntityManager;
use App\System\CommandExecutor;
use App\System\Command\CheckIfFileExistsCommand;
use App\System\Command\ChownCommand;
use App\System\Command\FindChmodSecureCommand;
use App\System\Command\ReadLinkCommand;
class SystemPermissionsResetCommand extends BaseCommand
{
    private SiteEntityManager $siteEntityManager;
    private SshUserEntityManager $sshUserEntityManager;
    public function __construct(SiteEntityManager $siteEntityManager, SshUserEntityManager $sshUserEntityManager)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->sshUserEntityManager = $sshUserEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("system:permissions:reset");
        $this->setDescription("clpctl system:permissions:reset --directories=770 --files=660 --path=.");
        $this->addOption("directories", null, InputOption::VALUE_REQUIRED, false);
        $this->addOption("files", null, InputOption::VALUE_REQUIRED, false);
        $this->addOption("path", null, InputOption::VALUE_REQUIRED, false);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $path = (string) $input->getOption("path");
            $chmodDirectories = (string) $input->getOption("directories");
            $chmodFiles = (string) $input->getOption("files");
            if (".." == $path) {
                $output->writeln("<error>Not Allowed!</error>");
                return BaseCommand::FAILURE;
            }
            if ("." == $path) {
                $path = sprintf("%s/", rtrim(getcwd(), "/"));
            } elseif (false === str_starts_with($path, "/home/")) {
                $path = sprintf("%s/%s", rtrim(getcwd(), "/"), $path);
            }
            if (true === str_contains($path, "../")) {
                $path = realpath($path);
            }
            $systemUserName = $_SERVER["SUDO_USER"] ?? null;
            $allowedDirectories = [];
            $user = null;
            $group = null;
            if (false === is_null($systemUserName)) {
                $commandExecutor = new CommandExecutor();
                $siteEntity = $this->siteEntityManager->findOneByUser($systemUserName);
                if (true === is_null($siteEntity)) {
                    $sshUserEntity = $this->sshUserEntityManager->findOneByUserName($systemUserName);
                    if (false === is_null($sshUserEntity)) {
                        $siteEntity = $sshUserEntity->getSite();
                        $user = $sshUserEntity->getUserName();
                        $group = $siteEntity->getUser();
                        $allowedDirectories = [sprintf("/home/%s/", $siteEntity->getUser()), sprintf("/home/%s/", $sshUserEntity->getUserName())];
                    }
                } else {
                    $allowedDirectories[] = sprintf("/home/%s/", $siteEntity->getUser());
                    $user = $siteEntity->getUser();
                    $group = $user;
                }
                $readLinkCommand = new ReadLinkCommand();
                $readLinkCommand->setFile($path);
                $commandExecutor->execute($readLinkCommand);
                $readLinkPath = $readLinkCommand->getOutput();
                if (false === empty($readLinkPath) && $path != $readLinkPath) {
                    $path = $readLinkPath;
                }
                if (false === empty($allowedDirectories) && false === is_null($user) && false === is_null($group)) {
                    $isValidDirectory = false;
                    foreach ($allowedDirectories as $allowedDirectory) {
                        if (!(true === str_starts_with($path, $allowedDirectory))) {
                            continue;
                        }
                        $isValidDirectory = true;
                        break;
                    }
                    if (true === $isValidDirectory) {
                        try {
                            $checkIfFileExistsCommand = new CheckIfFileExistsCommand();
                            $checkIfFileExistsCommand->setFile($path);
                            $commandExecutor->execute($checkIfFileExistsCommand);
                        } catch (\Exception $e) {
                            $errorMessage = sprintf("Path \"%s\" does not exist.", $path);
                            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
                            return BaseCommand::FAILURE;
                        }
                        $chownCommand = new ChownCommand();
                        $chownCommand->setUser($user);
                        $chownCommand->setGroup($group);
                        $chownCommand->setFile($path);
                        $chownCommand->setRecursive(true);
                        $chmodCommand = new FindChmodSecureCommand();
                        $chmodCommand->setDirectoryChmod($chmodDirectories);
                        $chmodCommand->setFileChmod($chmodFiles);
                        $chmodCommand->setFile($path);
                        $commandExecutor->execute($chownCommand, 1800);
                        $commandExecutor->execute($chmodCommand, 1800);
                        $output->writeln("<info>Permissions have been reset.</info>");
                    }
                }
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $logger = $this->getLogger();
            $logger->exception($e);
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}