<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
class ListCommand extends BaseCommand
{
    protected OutputInterface $output;
    private array $commands = [];
    protected function configure() : void
    {
        $this->setName("app:list");
        $this->setDescription("List commands");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->output = $output;
        $groupedCommands = $this->getCommands();
        if (count($groupedCommands)) {
            $application = $this->getApplication();
            $this->output->writeln(sprintf("<info>%s</info>", $application->getName()));
            $this->output->writeln('');
            ksort($groupedCommands);
            foreach ($groupedCommands as $groupName => $commands) {
                $this->output->writeln(sprintf("<comment>%s</comment>", $groupName), true);
                foreach ($commands as $command) {
                    $comment = $command->getComment();
                    if (false === empty($comment)) {
                        $this->output->writeln(sprintf(" <fg=gray>// %s</>", $comment));
                    }
                    $this->output->writeln(sprintf(" <fg=white>%s</>", $command->getDescription()));
                }
                $this->output->writeln('');
            }
        }
        return BaseCommand::SUCCESS;
    }
    private function getCommands() : array
    {
        if (true === empty($this->commands)) {
            $application = $this->getApplication();
            $commandLoader = $application->getCommandLoader();
            $commandNames = $commandLoader->getNames();
            foreach ($commandNames as $commandName) {
                if (!(true === $commandLoader->has($commandName))) {
                    continue;
                }
                $command = $commandLoader->get($commandName);
                $commandName = $command->getName();
                $groupName = $command->getGroupName();
                $this->commands[$groupName][$commandName] = $command;
            }
        }
        return $this->commands;
    }
}