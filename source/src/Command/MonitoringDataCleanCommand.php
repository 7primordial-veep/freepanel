<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Command\Command as BaseCommand;
class MonitoringDataCleanCommand extends BaseCommand
{
    private ManagerRegistry $managerRegistry;
    public function __construct(ManagerRegistry $managerRegistry)
    {
        $this->managerRegistry = $managerRegistry;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("monitoring:data:clean");
        $this->setDescription("Cleans up monitoring data");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $entityManager = $this->managerRegistry->getManager();
            $oldDataDateTime = new \DateTime("now");
            $oldDataDateTime->modify("-24 hours");
            $entities = ["App:InstanceCpu", "App:InstanceMemory", "App:InstanceDiskUsage", "App:InstanceLoadAverage"];
            foreach ($entities as $entityName) {
                $query = $entityManager->createQuery(sprintf("DELETE %s s where s.createdAt < :createdAt", $entityName));
                $query->execute(["createdAt" => $oldDataDateTime]);
            }
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
}