<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminDoAccessTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("accessToken", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "ed2831ea268be33eb96aec87e3f478a3a5b56598323d76b67e18d90f1ceaf5803"], "label" => "Access Token"]);
    }
    public function getName() : string
    {
        return "clp_admin_do_access_token";
    }
}