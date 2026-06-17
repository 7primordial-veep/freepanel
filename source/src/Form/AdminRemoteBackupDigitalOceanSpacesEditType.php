<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use App\Validator\Constraints as AppAssert;
use App\Backup\Frequency;
class AdminRemoteBackupDigitalOceanSpacesEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("enableRemoteBackup", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "Enable Remote Backup"]);
        $frequencyChoices = ["Daily" => Frequency::DAILY, "Every Three Hours" => Frequency::EVERY_THREE_HOURS, "Every Six Hours" => Frequency::EVERY_SIX_HOURS, "Every Twelve Hours" => Frequency::EVERY_TWELVE_HOURS];
        $builder->add("frequency", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Frequency", "choices" => $frequencyChoices]);
        $timeChoices = [];
        foreach (range(0, 23) as $number) {
            $timeFormatted = sprintf("%s:00", $number < 10 ? sprintf("0%s", $number) : $number);
            $timeChoices[$timeFormatted] = $number;
        }
        $builder->add("executionTime", ChoiceType::class, ["required" => false, "attr" => ["class" => "form-select form-select-lg"], "label" => "Time", "placeholder" => false, "choices" => $timeChoices]);
        $builder->add("accessKey", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "AKIAJPX1RHHVYWQ45NSA87"], "label" => "Access Key", "constraints" => [new AppAssert\DigitalOceanSpacesCredentials()]]);
        $builder->add("secretAccessKey", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Secret Access Key"]);
        $builder->add("space", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "Space Name"], "label" => "Space Name"]);
        $builder->add("spaceEndpoint", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "fra1.digitaloceanspaces.com"], "label" => "Space Endpoint"]);
        $builder->add("storageDirectory", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Storage Directory"]);
        $retentionPeriodChoices = ["3" => 3, "7" => 7, "14" => 14, "21" => 21, "30" => 30];
        $builder->add("retentionPeriod", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Retention Period (Days)", "choices" => $retentionPeriodChoices]);
        $excludesPlaceholder = ["/home/site-user/htdocs/domain.com/cache/", "/home/site-user/htdocs/domain.com/file.txt"];
        $builder->add("excludes", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5, "placeholder" => implode(PHP_EOL, $excludesPlaceholder)], "label" => "Excludes", "constraints" => [new AppAssert\RemoteBackupExcludes()]]);
    }
    public function getName() : string
    {
        return "clp_admin_remote_backup_digital_ocean_spaces";
    }
}