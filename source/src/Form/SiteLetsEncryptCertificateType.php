<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
class SiteLetsEncryptCertificateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
    }
    public function getName() : string
    {
        return "clp_site_lets_encrypt_certificate";
    }
}