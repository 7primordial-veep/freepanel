<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
class SiteDatabaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("name", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Database Name"]);
        $builder->add("userName", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Database User Name", "constraints" => [new Assert\NotBlank(), new Assert\Regex("/^[a-z][-a-z0-9]+\$/iu"), new Assert\Length(["min" => 3]), new Assert\Length(["max" => 32]), new AppAssert\DatabaseUserName()]]);
        $builder->add("userPassword", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Database User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8]), new Assert\Length(["max" => 40])]]);
    }
    public function getName() : string
    {
        return "clp_site_database";
    }
}