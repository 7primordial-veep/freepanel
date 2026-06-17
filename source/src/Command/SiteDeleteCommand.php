<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use App\Command\SiteCommand as SiteCommand;
class SiteDeleteCommand extends SiteCommand
{
    protected function configure() : void
    {
        $this->setName("site:delete");
        $this->setDescription("clpctl site:delete --domainName=www.domain.com");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("force", null, InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $domainName = trim($input->getOption("domainName"));
            $force = $input->getOption("force") === true;
            $site = $this->getSite($domainName);
            if (false === $force) {
                $helper = $this->getHelper("question");
                $question = new ConfirmationQuestion(sprintf("<info>Confirm to delete the site: %s</info> <comment>(yes/no):</comment> ", $domainName), false, "/^(yes)/i");
                $answer = $helper->ask($input, $output, $question);
                if (false === $answer) {
                    return SiteCommand::SUCCESS;
                }
            }
            $siteEntity = $this->getSiteEntity($domainName);
            if (false === is_null($siteEntity)) {
                $siteDeleter = $this->getSiteDeleter($site);
                $siteDeleter->delete();
                $this->siteEntityManager->deleteEntity($siteEntity);
                $output->writeln(sprintf("<info>Site</info> <comment>%s</comment> <info>has been deleted.</info>", $domainName));
            }
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
}