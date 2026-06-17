<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminHetznerApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("apiToken", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "9crEB30IharQZjZLa7DQEp1ktjysqYKoS24ClVZIxtrtQImooLgQImW5XOgiEmgZ"], "label" => "Api Token"]);
    }
    public function getName() : string
    {
        return "clp_admin_hetzner_api_token";
    }
}