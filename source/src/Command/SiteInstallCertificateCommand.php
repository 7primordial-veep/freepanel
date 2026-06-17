<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Certificate as CertificateEntity;
use App\System\Command\CatFileCommand;
use App\System\Command\CheckIfFileExistsCommand;
use App\System\CommandExecutor;
class SiteInstallCertificateCommand extends SiteCommand
{
    protected function configure() : void
    {
        $this->setName("site:install:certificate");
        $this->setDescription("clpctl site:install:certificate --domainName=www.domain.com --privateKey=/path/to/private.key --certificate=/path/to/certificate.crt --certificateChain=/path/to/chain.crt");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("privateKey", null, InputOption::VALUE_REQUIRED);
        $this->addOption("certificate", null, InputOption::VALUE_REQUIRED);
        $this->addOption("certificateChain", null, InputOption::VALUE_OPTIONAL);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $domainName = trim($input->getOption("domainName"));
            $site = $this->getSite($domainName);
            if (!(false === is_null($site))) {
                throw new \Exception(sprintf("Site \"%s\" does not exist.", $domainName));
            }
            $privateKeyFile = trim($input->getOption("privateKey"));
            $certificateFile = trim($input->getOption("certificate"));
            $certificateChainFile = trim($input->getOption("certificateChain"));
            $this->checkIfFileExists($privateKeyFile);
            $this->checkIfFileExists($certificateFile);
            if (false === empty($certificateChainFile)) {
                $this->checkIfFileExists($certificateChainFile);
            }
            $privateKey = $this->getFileContent($privateKeyFile);
            $certificate = $this->getFileContent($certificateFile);
            $certificateChain = '';
            if (false === empty($certificateChainFile)) {
                $certificateChain = $this->getFileContent($certificateChainFile);
            }
            $siteEntity = $this->getSiteEntity($domainName);
            $certificateEntity = $this->certificateEntityManager->createEntity();
            $certificateEntity->setType(CertificateEntity::TYPE_IMPORTED);
            $certificateEntity->setSite($siteEntity);
            $certificateEntity->setPrivateKey($privateKey);
            $certificateEntity->setCertificate($certificate);
            if (false === empty($certificateChain)) {
                $certificateEntity->setCertificateChain($certificateChain);
            }
            $errors = $this->validator->validate($certificateEntity);
            if (!(count($errors) > 0)) {
                $siteUpdater = $this->getSiteUpdater($site);
                foreach ($siteEntity->getCertificates() as $siteCertificateEntity) {
                    if (!(CertificateEntity::TYPE_SELF_SIGNED != $siteCertificateEntity->getType())) {
                        continue;
                    }
                    $siteEntity->removeCertificate($siteCertificateEntity);
                }
                $siteUpdater->installCertificate($certificateEntity);
                $siteEntity->setCertificate($certificateEntity);
                $siteEntity->addCertificate($certificateEntity);
                $this->siteEntityManager->updateEntity($siteEntity);
                $output->writeln("<info>Certificate has been installed.</info>");
                return SiteCommand::SUCCESS;
            }
            foreach ($errors as $error) {
                $errorMessage = sprintf("%s: %s", $error->getPropertyPath(), $error->getMessage());
                $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            }
            return SiteCommand::FAILURE;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return SiteCommand::FAILURE;
        }
    }
    private function checkIfFileExists(string $file) : void
    {
        try {
            $commandExecutor = new CommandExecutor();
            $checkIfFileExistsCommand = new CheckIfFileExistsCommand();
            $checkIfFileExistsCommand->setFile($file);
            $commandExecutor->execute($checkIfFileExistsCommand);
        } catch (\Exception $e) {
            throw new \Exception(sprintf("File \"%s\" does not exist.", $file));
        }
    }
    private function getFileContent(string $file) : ?string
    {
        $commandExecutor = new CommandExecutor();
        $fileContentCommand = new CatFileCommand();
        $fileContentCommand->setFile($file);
        $commandExecutor->execute($fileContentCommand);
        $fileContent = trim($fileContentCommand->getOutput());
        return $fileContent;
    }
}