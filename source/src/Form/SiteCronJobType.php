<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Site as SiteEntity;
class SiteCronJobType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $cronJobEntity = $options["data"];
        $siteEntity = $cronJobEntity->getSite();
        $templateChoices = ["Every minute" => "* * * * *", "Every 5 minutes" => "*/5 * * * *", "Once an hour" => "0 * * * *", "Every 3 hours" => "0 */3 * * *", "Once a day (At 00:00)" => "0 0 * * *", "Every day at 4 a.m." => "0 4 * * *", "Once a week (At 00:00 on Sunday)" => "0 0 * * 0", "Once a month (At 00:00 on day-of-month 1)" => "0 0 1 * *"];
        $builder->add("template", ChoiceType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-select form-select-lg"], "label" => "Template", "choices" => $templateChoices]);
        $builder->add("minute", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "*"], "label" => "Minute", "constraints" => [new Assert\Regex("/^(\\*|([0-5]?\\d)(-(\\d+))?)(\\/\\d+)?(,([0-5]?\\d)(-(\\d+))?)*(\\/\\d+)?\$/")]]);
        $builder->add("hour", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "*"], "label" => "Hour", "constraints" => [new Assert\Regex("/^(?:\\*|[01]?\\d|2[0-3])(?:,(?:[01]?\\d|2[0-3]))*(?:-(?:[01]?\\d|2[0-3]))?(?:\\/(?:[01]?\\d|2[0-3]))?\$/")]]);
        $builder->add("day", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "*"], "label" => "Day", "constraints" => [new Assert\Regex("/^(\\*|([1-9]|[12][0-9]|3[01])(,([1-9]|[12][0-9]|3[01]))*|(([1-9]|[12][0-9]|3[01])\\-([1-9]|[12][0-9]|3[01]))|(\\*\\/[1-9][0-9]?)|([1-9]|[12][0-9]|3[01])\\/[1-9][0-9]?)\$/")]]);
        $builder->add("month", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "*"], "label" => "Month", "constraints" => [new Assert\Regex("/^(?:\\*(?![0-9-])|(?:[1-9]|1[0-2])(?:-(?:[1-9]|1[0-2]))?(?:,(?:[1-9]|1[0-2])(?:-(?:[1-9]|1[0-2]))?)*)\$/")]]);
        $builder->add("weekday", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "*"], "label" => "Weekday", "constraints" => [new Assert\Regex("/^(?:\\*|([0-7](?:-[0-7])?(?:,[0-7](?:-[0-7])?)*)|\\*\\/[1-7])\$/")]]);
        if (SiteEntity::TYPE_PHP == $siteEntity->getType()) {
            $phpSettings = $siteEntity->getPhpSettings();
            $phpVersion = $phpSettings->getPhpVersion();
            $commandDefaultValue = sprintf("/usr/bin/php%s /home/%s/htdocs/%s/script.php", $phpVersion, $siteEntity->getUser(), rtrim($siteEntity->getRootDirectory(), "/"));
        } else {
            $commandDefaultValue = sprintf("/home/%s/htdocs/%s/script.sh", $siteEntity->getUser(), rtrim($siteEntity->getRootDirectory(), "/"));
        }
        $builder->add("command", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Command", "data" => $commandDefaultValue, "constraints" => [new Assert\Regex("/^(?!.*\\b(admin |clp|root|ubuntu|www-data)\\b)(?!.*\\n).*\$/")]]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["command"] = trim(str_replace(["\r", "\n"], '', (string) ($data["command"] ?? '')));
            $event->setData($data);
        });
    }
    public function getName() : string
    {
        return "clp_site_cron_job";
    }
}