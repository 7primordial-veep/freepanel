<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
class SiteLogsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $serviceChoices = ["NGINX" => "nginx", "PHP-FPM" => "php"];
        $builder->add("service", ChoiceType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-select"], "choices" => $serviceChoices, "data" => "nginx"]);
        $logFileChoices = ["access.log" => "access.log"];
        $builder->add("logFile", ChoiceType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-select"], "choices" => $logFileChoices, "data" => "access.log"]);
        $numberOfLinesChoices = [10 => 10, 25 => 25, 50 => 50, 100 => 100, 250 => 250, 500 => 500, 1000 => 1000, 2000 => 2000, 5000 => 5000, 10000 => 10000];
        $builder->add("numberOfLines", ChoiceType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-select"], "choices" => $numberOfLinesChoices, "data" => 25]);
    }
    public function getName() : string
    {
        return "clp_site_logs";
    }
}