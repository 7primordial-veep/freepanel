<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use App\Entity\DatabaseServer;
class AdminDatabaseServerEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("host", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "IP or DNS Name"], "label" => "Host"]);
        $builder->add("port", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => DatabaseServer::DEFAULT_PORT], "label" => "Port"]);
        $builder->add("userName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "root"], "label" => "User Name"]);
        $builder->add("password", PasswordType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "****************"], "label" => "Password"]);
        $builder->add("certificate", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 8], "label" => "Certificate"]);
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
        $resolver->setDefaults(["data_class" => "App\\Entity\\DatabaseServer"]);
    }
    public function getName() : string
    {
        return "clp_admin_database_server";
    }
}