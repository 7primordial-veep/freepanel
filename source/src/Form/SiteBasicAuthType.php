<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
class SiteBasicAuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("isActive", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "Basic Authentication"]);
        $builder->add("userName", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "john.doe"], "label" => "User Name"]);
        $builder->add("password", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "********"], "label" => "Password"]);
        $builder->add("whitelistedIps", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "1.1.1.1,2.2.2.2"], "label" => "Whitelisted IPs"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (true === isset($data["whitelistedIps"])) {
                $data["whitelistedIps"] = implode(",", array_map("trim", explode(",", $data["whitelistedIps"])));
            }
            $event->setData($data);
        });
    }
    public function getName() : string
    {
        return "clp_site_basic_auth";
    }
}