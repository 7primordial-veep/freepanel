<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints\MfaCode;
class UserSecurityMfaCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $user = $options["data"];
        $mfaCodeConstraint = new MfaCode();
        $mfaCodeConstraint->setUser($user);
        $builder->add("mfaCode", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg mfa-code", "placeholder" => "Enter authentication code"], "label" => "Authentication Code", "mapped" => false, "constraints" => [new Assert\NotNull(), $mfaCodeConstraint]]);
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
    }
    public function getName() : string
    {
        return "clp_mfa_code";
    }
}