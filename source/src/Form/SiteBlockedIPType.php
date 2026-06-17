<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
class SiteBlockedIPType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("ip", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "8.8.8.8"], "label" => "IP"]);
    }
    public function getName() : string
    {
        return "clp_site_blocked_ip";
    }
}