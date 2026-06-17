<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
class SiteImportCertificateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("privateKey", TextareaType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "rows" => 5], "label" => "Private Key"]);
        $builder->add("certificate", TextareaType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "rows" => 5], "label" => "Certificate"]);
        $builder->add("certificateChain", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5], "label" => "Certificate Chain"]);
    }
    public function getName() : string
    {
        return "clp_site_import_certificate";
    }
}