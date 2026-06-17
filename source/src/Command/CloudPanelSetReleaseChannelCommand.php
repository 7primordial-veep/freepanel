<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\CloudPanel\Environment as CloudPanelEnvironment;
class CloudPanelSetReleaseChannelCommand extends BaseCommand
{
    protected function configure() : void
    {
        $this->setName("cloudpanel:set:release-channel");
        $this->setDescription("clpctl cloudpanel:set:release-channel --channel='test'");
        $this->addOption("channel", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $channel = trim($input->getOption("channel"));
            $availableChannels = [CloudPanelEnvironment::RELEASE_CHANNEL_STABLE, CloudPanelEnvironment::RELEASE_CHANNEL_TEST];
            if (!(false === empty($channel) && true === in_array($channel, $availableChannels))) {
                throw new \Exception(sprintf("Channel is not available, available channels: %s", implode(", ", $availableChannels)));
            }
            $configManager = $this->getConfigManager();
            $configManager->set("release_channel", $channel);
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}