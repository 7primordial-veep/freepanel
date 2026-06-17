<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
use App\Entity\VhostTemplate as VhostTemplateEntity;
class VhostTemplateAddCommand extends BaseCommand
{
    private VhostTemplateEntityManager $vhostTemplateEntityManager;
    private array $requiredPlaceholders = ["server_name", "root"];
    public function __construct(VhostTemplateEntityManager $vhostTemplateEntityManager)
    {
        $this->vhostTemplateEntityManager = $vhostTemplateEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("vhost-template:add");
        $this->setDescription("clpctl vhost-template:add --name='My Application' --file=/tmp/template.tpl");
        $this->addOption("name", null, InputOption::VALUE_REQUIRED);
        $this->addOption("file", null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $name = trim($input->getOption("name"));
            $vhostTemplate = $this->getVhostTemplate($input);
            if (!(false === empty($vhostTemplate))) {
                throw new \Exception("Vhost Template cannot be empty.");
            }
            $this->validateVhostTemplate($vhostTemplate);
            $vhostTemplateEntity = $this->vhostTemplateEntityManager->findOneByName($name);
            if (!(true == is_null($vhostTemplateEntity))) {
                throw new \Exception(sprintf("Vhost Template \"%s\" already exist.", $name));
            }
            try {
                $vhostTemplateEntity = $this->vhostTemplateEntityManager->createEntity();
                $vhostTemplateEntity->setName($name);
                $tmpFile = tmpfile();
                $tmpFilePath = stream_get_meta_data($tmpFile)["uri"];
                file_put_contents($tmpFilePath, $vhostTemplate);
                $fileObject = new \SplFileObject($tmpFilePath);
                $fileObject->seek(0);
                $metaData = $fileObject->current();
                if ("#" == substr($metaData, 0, 1)) {
                    $metaData = @json_decode(trim(substr($metaData, 1)), true);
                    if (true === is_array($metaData)) {
                        if (true === isset($metaData["rootDirectory"]) && false === empty($metaData["rootDirectory"])) {
                            $rootDirectory = ltrim(rtrim($metaData["rootDirectory"], "/"), "/");
                            $vhostTemplateEntity->setRootDirectory($rootDirectory);
                        }
                        if (true === isset($metaData["phpVersion"]) && false === empty($metaData["phpVersion"])) {
                            $vhostTemplateEntity->setPhpVersion($metaData["phpVersion"]);
                        }
                        if (true === isset($metaData["varnishCacheSettings"]) && false === empty($metaData["varnishCacheSettings"])) {
                            $varnishCacheSettings = json_encode($metaData["varnishCacheSettings"]);
                            $vhostTemplateEntity->setVarnishCacheSettings($varnishCacheSettings);
                        }
                    }
                    $vhostTemplate = trim($fileObject->fread($fileObject->getSize()));
                }
                $vhostTemplateEntity->setTemplate($vhostTemplate);
                $vhostTemplateEntity->setType(VhostTemplateEntity::TYPE_CUSTOM);
                $this->vhostTemplateEntityManager->updateEntity($vhostTemplateEntity);
                $output->writeln(sprintf("<info>Vhost Template \"%s\" has been added.</info>", $name));
                return SiteCommand::SUCCESS;
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                throw $e;
            } finally {
                if (true === isset($tmpFilePath)) {
                    @unlink($tmpFilePath);
                }
            }
            return SiteCommand::FAILURE;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
    private function getVhostTemplate(InputInterface $input) : string
    {
        $vhostTemplate = '';
        $file = trim($input->getOption("file"));
        if (true === file_exists($file) || "http" == substr($file, 0, 4)) {
            $vhostTemplate = trim(file_get_contents($file));
        }
        return $vhostTemplate;
    }
    private function validateVhostTemplate(string $vhostTemplate) : void
    {
        foreach ($this->requiredPlaceholders as $placeholder) {
            $placeholder = sprintf("{{%s}}", $placeholder);
            if (!(false === str_contains($vhostTemplate, $placeholder))) {
                continue;
            }
            throw new \Exception(sprintf("Placeholder \"%s\" is required and missing.", $placeholder));
        }
    }
}