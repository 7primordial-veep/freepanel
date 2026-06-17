<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use App\Validator\Constraints as AppAssert;
use App\Backup\Frequency;
class AdminRemoteBackupGoogleDriveType extends AbstractType
{
    private array $serviceAccountPlaceholder = ["{", "  \"type\": \"service_account\",", "  \"project_id\": \"xxxx-project-id\",", "  \"private_key_id\": \"079ff00946151a15e54969bee627d4697356c8f2\",", "  ..............................................................", "}"];
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("enableRemoteBackup", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "Enable Remote Backup", "data" => true]);
        $frequencyChoices = ["Daily" => Frequency::DAILY, "Every Three Hours" => Frequency::EVERY_THREE_HOURS, "Every Six Hours" => Frequency::EVERY_SIX_HOURS, "Every Twelve Hours" => Frequency::EVERY_TWELVE_HOURS];
        $builder->add("frequency", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Frequency", "choices" => $frequencyChoices]);
        $timeChoices = [];
        foreach (range(0, 23) as $number) {
            $timeFormatted = sprintf("%s:00", $number < 10 ? sprintf("0%s", $number) : $number);
            $timeChoices[$timeFormatted] = $number;
        }
        $builder->add("executionTime", ChoiceType::class, ["required" => false, "attr" => ["class" => "form-select form-select-lg"], "label" => "Time", "placeholder" => false, "choices" => $timeChoices, "data" => 2]);
        $builder->add("email", EmailType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "john.doe@domain.com"], "label" => "E-Mail"]);
        $builder->add("serviceAccount", TextareaType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "rows" => 6, "placeholder" => implode(PHP_EOL, $this->serviceAccountPlaceholder)], "label" => "Service Account", "constraints" => [new AppAssert\GoogleDriveCredentials()]]);
        $builder->add("storageDirectory", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Storage Directory", "data" => "backups"]);
        $retentionPeriodChoices = ["3" => 3, "7" => 7, "14" => 14, "21" => 21, "30" => 30];
        $builder->add("retentionPeriod", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Retention Period (Days)", "choices" => $retentionPeriodChoices, "data" => 7]);
        $excludesPlaceholder = ["/home/site-user/htdocs/domain.com/cache/", "/home/site-user/htdocs/domain.com/file.txt"];
        $builder->add("excludes", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5, "placeholder" => implode(PHP_EOL, $excludesPlaceholder)], "label" => "Excludes", "constraints" => [new AppAssert\RemoteBackupExcludes()]]);
    }
    public function getName() : string
    {
        return "clp_admin_remote_backup_google_drive";
    }
}