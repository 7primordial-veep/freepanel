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
class AdminRemoteBackupWasabiType extends AbstractType
{
    private const DEFAULT_REGION = "us-east-1";
    private array $regions = [["name" => "Tokyo (ap-northeast-1)", "value" => "ap-northeast-1"], ["name" => "Osaka (ap-northeast-2)", "value" => "ap-northeast-2"], ["name" => "Singapore (ap-southeast-1)", "value" => "ap-southeast-1"], ["name" => "Sydney (ap-southeast-2)", "value" => "ap-southeast-2"], ["name" => "Toronto (ca-central-1)", "value" => "ca-central-1"], ["name" => "Amsterdam (eu-central-1)", "value" => "eu-central-1"], ["name" => "Frankfurt (eu-central-2)", "value" => "eu-central-2"], ["name" => "London (eu-west-1)", "value" => "eu-west-1"], ["name" => "Paris (eu-west-2)", "value" => "eu-west-2"], ["name" => "Oregon (us-west-1)", "value" => "us-west-1"], ["name" => "Texas (us-central-1)", "value" => "us-central-1"], ["name" => "N. Virginia (us-east-1)", "value" => "us-east-1"], ["name" => "N. Virginia (us-east-2)", "value" => "us-east-2"]];
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
        $builder->add("accessKey", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "7EOSL4M32ZJSVIAMMX187"], "label" => "Access Key", "constraints" => [new AppAssert\WasabiCredentials()]]);
        $builder->add("secretAccessKey", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "Bb9rPA66jQz5jbwyeEMuGaCaDoKDW4fIzArHRv7"], "label" => "Secret Access Key"]);
        $builder->add("bucket", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "Bucket Name"], "label" => "Bucket"]);
        $regionChoices = [];
        foreach ($this->regions as $region) {
            $regionChoices[$region["name"]] = $region["value"];
        }
        $builder->add("region", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Region", "choices" => $regionChoices, "data" => self::DEFAULT_REGION]);
        $builder->add("storageDirectory", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Storage Directory", "data" => "backups"]);
        $retentionPeriodChoices = ["3" => 3, "7" => 7, "14" => 14, "21" => 21, "30" => 30];
        $builder->add("retentionPeriod", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Retention Period (Days)", "choices" => $retentionPeriodChoices, "data" => 7]);
        $excludesPlaceholder = ["/home/site-user/htdocs/domain.com/cache/", "/home/site-user/htdocs/domain.com/file.txt"];
        $builder->add("excludes", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5, "placeholder" => implode(PHP_EOL, $excludesPlaceholder)], "label" => "Excludes", "constraints" => [new AppAssert\RemoteBackupExcludes()]]);
    }
    public function getName() : string
    {
        return "clp_admin_remote_backup_wasabi";
    }
}