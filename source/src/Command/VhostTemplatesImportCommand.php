<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use App\Command\Command as BaseCommand;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
use App\Entity\VhostTemplate as VhostTemplateEntity;
class VhostTemplatesImportCommand extends BaseCommand
{
    private const GIT_CLONE_PROCESS_TIMEOUT = 600;
    private VhostTemplateEntityManager $vhostTemplateEntityManager;
    private int $numberOfImportedVhostTemplates = 0;
    public function __construct(VhostTemplateEntityManager $vhostTemplateEntityManager)
    {
        $this->vhostTemplateEntityManager = $vhostTemplateEntityManager;
        parent::__construct();
    }
    protected function configure() : void
    {
        $this->setName("vhost-templates:import");
        $this->setDescription("clpctl vhost-templates:import");
        $this->addOption("delay", null, InputOption::VALUE_OPTIONAL, false);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $delay = (bool) $input->getOption("delay");
            if (true === $delay) {
                sleep(rand(1, 180));
            }
            $this->importVhostTemplates();
            $output->writeln(sprintf("<info>%s Vhost Templates have been imported.</info>", $this->numberOfImportedVhostTemplates));
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
    private function importVhostTemplates()
    {
        try {
            $filesystem = new Filesystem();
            $tmpVhostTemplatesDirectory = sprintf("%s/%s/", rtrim(sys_get_temp_dir(), "/"), uniqid());
            $source = $_ENV["APP_VHOST_GIT_REPOSITORY"];
            if (true === is_dir($source)) {
                // ponytail: local-dir mode. APP_VHOST_GIT_REPOSITORY can be a path to a bundled templates tree.
                $filesystem->mirror(rtrim($source, "/"), rtrim($tmpVhostTemplatesDirectory, "/"));
            } else {
                $gitCloneCommand = sprintf("/usr/bin/git clone %s %s", escapeshellarg($source), escapeshellarg($tmpVhostTemplatesDirectory));
                $process = Process::fromShellCommandline($gitCloneCommand);
                $process->setTimeout(self::GIT_CLONE_PROCESS_TIMEOUT);
                $process->run();
                if (!(true === $process->isSuccessful())) {
                    throw new ProcessFailedException($process);
                }
            }
            $applications = [];
            $vhostTemplatesDirectory = sprintf("%s/v%s/", rtrim($tmpVhostTemplatesDirectory, "/"), $_ENV["APP_VHOST_DIRECTORY"]);
            if (!(true === is_dir($vhostTemplatesDirectory))) {
                throw new \Exception(sprintf("Directory \"%s\" does not exist.", $vhostTemplatesDirectory));
            }
            $applicationIterator = new \DirectoryIterator($vhostTemplatesDirectory);
            foreach ($applicationIterator as $fileInfo) {
                if (!(true === $fileInfo->isDir() && "." != substr($fileInfo->getFilename(), 0, 1))) {
                    continue;
                }
                $applicationName = ucfirst($fileInfo->getFilename());
                $applicationVhostTemplateDirectory = $fileInfo->getPathname();
                if (!(true === is_dir($applicationVhostTemplateDirectory))) {
                    continue;
                }
                $vhostTemplates = [];
                $finder = new Finder();
                $finder->files()->in($applicationVhostTemplateDirectory);
                if (true === $finder->hasResults()) {
                    foreach ($finder as $file) {
                        $fileObject = new \SplFileObject($file->getPathname());
                        $fileObject->seek(0);
                        $metaData = $fileObject->current();
                        $data = [];
                        $vhostTemplateContent = trim($file->getContents());
                        if ("#" == substr($metaData, 0, 1)) {
                            $metaData = @json_decode(trim(substr($metaData, 1)), true);
                            if (true === is_array($metaData)) {
                                if (true === isset($metaData["rootDirectory"]) && false === empty($metaData["rootDirectory"])) {
                                    $data["rootDirectory"] = $metaData["rootDirectory"];
                                }
                                if (true === isset($metaData["phpVersion"]) && false === empty($metaData["phpVersion"])) {
                                    $data["phpVersion"] = $metaData["phpVersion"];
                                }
                                if (true === isset($metaData["varnishCacheSettings"]) && false === empty($metaData["varnishCacheSettings"])) {
                                    $data["varnishCacheSettings"] = $metaData["varnishCacheSettings"];
                                }
                            }
                            $vhostTemplateContent = trim($fileObject->fread($file->getSize()));
                        }
                        $vhostTemplateName = $file->getFilename();
                        if (!(false === empty($vhostTemplateName) && false === empty($vhostTemplateContent))) {
                            continue;
                        }
                        $data["template"] = $vhostTemplateContent;
                        $vhostTemplates[$vhostTemplateName] = $data;
                    }
                }
                if (!(false === empty($vhostTemplates))) {
                    continue;
                }
                $applications[$applicationName] = $vhostTemplates;
            }
            if (!(false === empty($applications))) {
                throw new \Exception("No vhost templates found.");
            }
            $this->vhostTemplateEntityManager->deleteTemplatesByType(VhostTemplateEntity::TYPE_SYSTEM);
            foreach ($applications as $templates) {
                foreach ($templates as $name => $data) {
                    if (!(false === empty($name) && true === isset($data["template"]) && false === empty($data["template"]))) {
                        continue;
                    }
                    $vhostTemplateEntity = $this->vhostTemplateEntityManager->createEntity();
                    $vhostTemplateEntity->setName($name);
                    $vhostTemplateEntity->setTemplate($data["template"]);
                    if (true === isset($data["rootDirectory"]) && false === empty($data["rootDirectory"])) {
                        $rootDirectory = ltrim(rtrim($data["rootDirectory"], "/"), "/");
                        $vhostTemplateEntity->setRootDirectory($rootDirectory);
                    }
                    if (true === isset($data["phpVersion"]) && false === empty($data["phpVersion"])) {
                        $vhostTemplateEntity->setPhpVersion($data["phpVersion"]);
                    }
                    if (true === isset($data["varnishCacheSettings"]) && false === empty($data["varnishCacheSettings"])) {
                        $varnishCacheSettings = json_encode($data["varnishCacheSettings"]);
                        $vhostTemplateEntity->setVarnishCacheSettings($varnishCacheSettings);
                    }
                    $vhostTemplateEntity->setType(VhostTemplateEntity::TYPE_SYSTEM);
                    $this->vhostTemplateEntityManager->updateEntity($vhostTemplateEntity);
                    $this->numberOfImportedVhostTemplates++;
                }
            }
        } catch (\Exception $e) {
            throw $e;
        } finally {
            if (true === isset($vhostTemplatesDirectory) && true === is_dir($vhostTemplatesDirectory)) {
                $filesystem->remove($tmpVhostTemplatesDirectory);
            }
        }
    }
}