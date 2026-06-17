<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;
class SiteNodejsSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $nodejsVersionChoices = ["Node 22 LTS" => 22, "Node 20 LTS" => 20, "Node 18 LTS" => 18, "Node 16 LTS" => 16, "Node 14 LTS" => 14, "Node 12 LTS" => 12];
        $builder->add("nodejsVersion", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Node.js Version", "choices" => $nodejsVersionChoices]);
        $builder->add("port", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "App Port"]);
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
        $resolver->setDefaults(["data_class" => "App\\Entity\\NodejsSettings"]);
    }
    public function getName() : string
    {
        return "clp_site_nodejs_settings";
    }
}