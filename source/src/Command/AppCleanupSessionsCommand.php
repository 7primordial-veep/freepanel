<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use App\Command\Command as BaseCommand;
class AppCleanupSessionsCommand extends BaseCommand
{
    private const SESSION_CLEANUP_DAYS = 2;
    protected function configure() : void
    {
        $this->setName("app:clean-up:sessions");
        $this->setDescription("clpctl app:clean-up:sessions");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->cleanUpSessions();
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function cleanUpSessions() : void
    {
        $projectDirectory = $this->getApplication()->getKernel()->getProjectDir();
        $sessionDirectory = sprintf("%s/var/sessions/", $projectDirectory);
        $filesystem = new Filesystem();
        if (true === $filesystem->exists($sessionDirectory)) {
            $finder = new Finder();
            $finder->files()->in($sessionDirectory)->date(sprintf("<= now - %d days", self::SESSION_CLEANUP_DAYS));
            foreach ($finder as $file) {
                $filesystem->remove($file->getRealPath());
            }
        }
    }
}