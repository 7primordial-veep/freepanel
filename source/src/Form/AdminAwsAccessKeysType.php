<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
class AdminAwsAccessKeysType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("accessKey", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "AKIAJPX1RHHVYWQ45NSA87"], "label" => "AWS Access Key"]);
        $builder->add("secretAccessKey", PasswordType::class, ["required" => true, "always_empty" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "lubkWMvb5f46GWoniX7oYbh1wq7XP+LUObym+HG1"], "label" => "AWS Secret Access Key"]);
    }
    public function getName() : string
    {
        return "clp_admin_aws_access_keys";
    }
}