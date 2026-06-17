<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminBasicAuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("userName", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "User Name"]);
        $builder->add("password", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Password"]);
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
    }
    public function getName() : string
    {
        return "clp_admin_basic_auth";
    }
}