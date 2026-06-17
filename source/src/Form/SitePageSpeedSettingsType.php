<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
class SitePageSpeedSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("isEnabled", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "PageSpeed"]);
        $builder->add("settings", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 10], "label" => "Settings"]);
    }
    public function getName() : string
    {
        return "clp_site_page_speed_settings";
    }
}