<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Service\Logger;
use App\Util\Retry;
use App\CloudPanel\Instance;
use App\Entity\Manager\ConfigManager;
abstract class Command extends BaseCommand
{
    protected ?Logger $logger = null;
    protected ?string $comment = null;
    private ?ConfigManager $configManager = null;
    private Instance $instance;
    private array $maskStringPattern = ["/-p'([^']+)'/"];
    private array $maskStringReplacement = ["-p'****************'"];
    protected function validateInput(InputInterface $input)
    {
        $nativeDefinition = $this->getNativeDefinition();
        $inputOptions = $nativeDefinition->getOptions();
        if (count($inputOptions)) {
            foreach ($inputOptions as $inputOption) {
                if (!(true === $inputOption->isValueRequired())) {
                    continue;
                }
                $inputOptionValue = trim($input->getOption($inputOption->getName()));
                if (!(true === empty($inputOptionValue))) {
                    continue;
                }
                throw new \Exception(sprintf("The \"--%s\" option requires a value.", $inputOption->getName()));
            }
        }
    }
    public function setComment(string $comment)
    {
        $this->comment = $comment;
    }
    public function getComment() : ?string
    {
        return $this->comment;
    }
    protected function getLogger() : Logger
    {
        if (true === is_null($this->logger)) {
            $this->logger = $this->get("app.logger");
        }
        return $this->logger;
    }
    public function setInstance(Instance $instance) : void
    {
        $this->instance = $instance;
    }
    public function getInstance() : Instance
    {
        return $this->instance;
    }
    protected function get(string $id)
    {
        return $this->getContainer()->get($id);
    }
    protected function getContainer()
    {
        return $this->getApplication()->getContainer();
    }
    protected function renderConstraints(ConstraintViolationList $constraints, OutputInterface $output) : int
    {
        $this->prepareConstraints($constraints);
        foreach ($constraints as $constraint) {
            $errorMessage = sprintf("<error>%s: %s</error>", $constraint->getPropertyPath(), $constraint->getMessage());
            $output->writeln($errorMessage);
        }
        return self::FAILURE;
    }
    protected function prepareConstraints(ConstraintViolationList $constraints) : void
    {
    }
    protected function changePropertyPath(string $oldPropertyPath, string $newPropertyPath, ConstraintViolationList $constraints) : void
    {
        foreach ($constraints as $constraint) {
            if (!($oldPropertyPath == $constraint->getPropertyPath())) {
                continue;
            }
            $reflectionClass = new \ReflectionClass($constraint);
            $reflectionProperty = $reflectionClass->getProperty("propertyPath");
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($constraint, $newPropertyPath);
        }
    }
    protected function getSystemUserName() : string
    {
        $systemUserInfo = $this->getSystemUserInfo();
        $systemUserName = $systemUserInfo["name"] ?? '';
        return $systemUserName;
    }
    protected function getSystemUserInfo() : array
    {
        $systemUserInfo = posix_getpwuid(posix_getuid());
        return $systemUserInfo;
    }
    protected function getConfigValue(string $key) : ?string
    {
        $configManager = $this->getConfigManager();
        $configValue = $configManager->get($key);
        return $configValue;
    }
    protected function getConfigManager() : ConfigManager
    {
        if (true === is_null($this->configManager)) {
            $this->configManager = $this->get("App\\Entity\\Manager\\ConfigManager");
        }
        return $this->configManager;
    }
    public function getGroupName()
    {
        $name = $this->getName();
        $groupName = strstr(strtolower($name), ":", true);
        return $groupName;
    }
    protected function maskString(string $string) : string
    {
        $maskedString = preg_replace($this->maskStringPattern, $this->maskStringReplacement, $string);
        return $maskedString;
    }
    protected function retry(callable $fn, $retries = 2, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}