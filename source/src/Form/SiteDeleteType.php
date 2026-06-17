<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class SiteDeleteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $data = $options["data"];
        $domainName = $data["domainName"] ?? '';
        $builder->add("cancel", ButtonType::class, ["attr" => ["class" => "btn btn-lg btn-gray", "data-bs-dismiss" => "modal"], "label" => "Cancel"]);
        $builder->add("domainName", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-red-focus", "data-domain-name" => $domainName, "placeholder" => $domainName], "label" => "DomainName"]);
    }
    public function getName() : string
    {
        return "clp_site_delete";
    }
}