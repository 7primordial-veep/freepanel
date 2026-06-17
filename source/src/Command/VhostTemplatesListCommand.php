<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
use App\Entity\VhostTemplate as VhostTemplateEntity;
class VhostTemplatesListCommand extends BaseCommand
{
    private const IGNORED_TEMPLATES = ["nodejs", "python", "reverseproxy", "static"];
    private VhostTemplateEntityManager $vhostTemplateEntityManager;
    public function __construct(VhostTemplateEntityManager $vhostTemplateEntityManager)
    {
        $this->vhostTemplateEntityManager = $vhostTemplateEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("vhost-templates:list");
        $this->setDescription("clpctl vhost-templates:list");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $vhostTemplates = $this->vhostTemplateEntityManager->findAll([], ["name" => "ASC"]);
            if (count($vhostTemplates)) {
                $tableRows = [];
                $table = new Table($output);
                $table->setHeaders(["Name", "Root Directory", "Type"]);
                foreach ($vhostTemplates as $vhostTemplate) {
                    $name = $vhostTemplate->getName();
                    if (!(false === empty($name) && false === in_array(strtolower($name), self::IGNORED_TEMPLATES))) {
                        continue;
                    }
                    $type = VhostTemplateEntity::TYPE_SYSTEM == $vhostTemplate->getType() ? "System" : "Custom";
                    $rootDirectory = $vhostTemplate->getRootDirectory();
                    $tableRows[] = ["Name" => $name, "Root Directory" => $rootDirectory, "Type" => $type];
                }
                $table->setRows($tableRows);
                $table->render();
            }
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
}