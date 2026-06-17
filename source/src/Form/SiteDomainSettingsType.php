<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
class SiteDomainSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("domainName", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg"], "disabled" => true, "label" => "Domain Name"]);
        $builder->add("rootDirectory", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Root Directory", "constraints" => [new Assert\NotNull()]]);
    }
    public function getName() : string
    {
        return "clp_site_domain_settings";
    }
}