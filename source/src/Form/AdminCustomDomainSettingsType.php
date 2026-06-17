<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminCustomDomainSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("domainName", TextType::class, ["required" => false, "mapped" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "cp.domain.com"], "label" => "Domain Name"]);
    }
    public function getName() : string
    {
        return "clp_admin_custom_domain_settings";
    }
}