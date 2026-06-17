<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
class SiteCloudflareType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("allowTrafficFromCloudflareOnly", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "Allow traffic from Cloudflare only"]);
    }
    public function getName() : string
    {
        return "clp_site_cloudflare";
    }
}