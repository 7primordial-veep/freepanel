<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
class AdminVultrSnapshotsSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("automaticSnapshots", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "Enable Automatic Snapshots"]);
        $frequencyChoices = ["Every Three Hours" => 3, "Every Six Hours" => 6, "Every Twelve Hours" => 12, "Once per Day" => 24];
        $builder->add("frequency", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Frequency", "choices" => $frequencyChoices]);
        $retentionPeriodChoices = ["1" => 1, "3" => 3, "7" => 7, "14" => 14, "21" => 21, "30" => 30];
        $builder->add("retentionPeriod", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Retention Period (Days)", "choices" => $retentionPeriodChoices]);
    }
    public function getName() : string
    {
        return "clp_admin_vultr_snapshots_settings";
    }
}