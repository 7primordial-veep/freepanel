<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
class SitePhpSettingsType extends AbstractType
{
    const PHP_DIRECTORY = "/etc/php/";
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $phpVersionChoices = $this->getPhpVersionChoices();
        $builder->add("phpVersion", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "PHP Version", "choices" => $phpVersionChoices]);
        $memoryLimitChoices = ["64 MB" => "64M", "128 MB" => "128M", "256 MB" => "256M", "512 MB" => "512M", "768 MB" => "768M", "1 GB" => "1G", "2 GB" => "2G", "3 GB" => "3G", "4 GB" => "4G", "5 GB" => "5G"];
        $builder->add("memoryLimit", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "memory_limit", "choices" => $memoryLimitChoices]);
        $maxExecutionTimeChoices = ["15s" => "15", "30s" => "30", "1m" => "60", "2m" => "120", "3m" => "180", "5m" => "300", "10m" => "600", "15m" => "900", "30m" => "1800", "1h" => "3600"];
        $builder->add("maxExecutionTime", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "max_execution_time", "choices" => $maxExecutionTimeChoices]);
        $maxInputTimeChoices = ["15s" => "15", "30s" => "30", "1m" => "60", "2m" => "120", "3m" => "180", "5m" => "300", "10m" => "600", "15m" => "900", "30m" => "1800", "1h" => "3600"];
        $builder->add("maxInputTime", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "max_input_time", "choices" => $maxInputTimeChoices]);
        $maxInputVarsChoices = ["1000" => 1000, "2000" => 2000, "5000" => 5000, "10000" => 10000, "20000" => 20000, "50000" => 50000, "100000" => 100000];
        $builder->add("maxInputVars", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "max_input_vars", "choices" => $maxInputVarsChoices]);
        $postMaxSizeChoices = ["2 MB" => "2M", "4 MB" => "4M", "8 MB" => "8M", "16 MB" => "16M", "32 MB" => "32M", "64 MB" => "64M", "128 MB" => "128M", "256 MB" => "256M", "512 MB" => "512M", "768 MB" => "768M", "1 GB" => "1G", "2 GB" => "2G", "3 GB" => "3G", "4 GB" => "4G", "5 GB" => "5G"];
        $builder->add("postMaxSize", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "post_max_size", "choices" => $postMaxSizeChoices]);
        $uploadMaxFilesizeChoices = ["2 MB" => "2M", "4 MB" => "4M", "8 MB" => "8M", "16 MB" => "16M", "32 MB" => "32M", "64 MB" => "64M", "128 MB" => "128M", "256 MB" => "256M", "512 MB" => "512M", "768 MB" => "768M", "1 GB" => "1G", "2 GB" => "2G", "3 GB" => "3G", "4 GB" => "4G", "5 GB" => "5G"];
        $builder->add("uploadMaxFilesize", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "upload_max_filesize", "choices" => $uploadMaxFilesizeChoices]);
        $builder->add("additionalConfiguration", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5], "label" => "Additional Configuration Directives"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (true === isset($data["additionalConfiguration"]) && false === empty($data["additionalConfiguration"])) {
                $additionalConfiguration = trim($data["additionalConfiguration"]);
                $additionalConfiguration = explode(PHP_EOL, $additionalConfiguration);
                $additionalConfigurationSettings = [];
                if (count($additionalConfiguration)) {
                    foreach ($additionalConfiguration as $value) {
                        $value = trim($value);
                        $value = str_replace(["\""], ["'"], $value);
                        if (!(false === empty($value))) {
                            continue;
                        }
                        if (";" != substr($value, -1)) {
                            $value .= ";";
                        }
                        $additionalConfigurationSettings[] = $value;
                    }
                }
                if (false === empty($additionalConfigurationSettings)) {
                    $data["additionalConfiguration"] = implode(PHP_EOL, $additionalConfigurationSettings);
                }
            }
            $event->setData($data);
        });
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
        $resolver->setDefaults(["data_class" => "App\\Entity\\PhpSettings"]);
    }
    public function getName() : string
    {
        return "clp_site_php_settings";
    }
    private function getPhpVersionChoices() : array
    {
        $phpVersionChoices = [];
        foreach (new \DirectoryIterator(self::PHP_DIRECTORY) as $fileInfo) {
            if (!(false === $fileInfo->isDot())) {
                continue;
            }
            $phpVersion = $fileInfo->getBasename();
            if (!(true === is_float($phpVersion + 0))) {
                continue;
            }
            $phpVersionChoices[sprintf("PHP %s", $phpVersion)] = $phpVersion;
        }
        arsort($phpVersionChoices);
        return $phpVersionChoices;
    }
}