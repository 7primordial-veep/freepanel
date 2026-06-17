<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class SiteReverseProxySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("reverseProxyUrl", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "http://127.0.0.1:8000"], "label" => "Reverse Proxy Url"]);
    }
    public function getName() : string
    {
        return "clp_site_reverse_proxy_settings";
    }
}