<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
class AdminGceServiceAccountKeysType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("serviceAccountKeys", TextareaType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "rows" => 10], "label" => "Service Account Keys"]);
    }
    public function getName() : string
    {
        return "clp_admin_gce_service_account_keys";
    }
}