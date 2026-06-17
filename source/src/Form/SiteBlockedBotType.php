<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
class SiteBlockedBotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("name", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "FatBot"], "label" => "Bot Name"]);
    }
    public function getName() : string
    {
        return "clp_site_blocked_bot";
    }
}