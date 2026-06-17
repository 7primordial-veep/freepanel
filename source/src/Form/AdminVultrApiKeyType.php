<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminVultrApiKeyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("apiKey", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "LMU3HMCA4NBZ6NQ3H6HYRJ7L6Z2XFTQS87"], "label" => "Api Key"]);
    }
    public function getName() : string
    {
        return "clp_admin_vultr_api_key";
    }
}