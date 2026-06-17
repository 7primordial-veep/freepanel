<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminProftpdSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("masqueradeAddress", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "8.8.8.8"], "label" => "MasqueradeAddress", "constraints" => [new Assert\Ip()]]);
    }
    public function getName() : string
    {
        return "clp_admin_proftpd_settings";
    }
}