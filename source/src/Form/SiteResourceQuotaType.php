<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class SiteResourceQuotaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('cpuQuota', IntegerType::class, [
            'required' => false,
            'label' => 'CPU Quota (%)',
            'attr' => [
                'class' => 'form-control form-control-lg',
                'min' => 0,
                'max' => 1600,
                'step' => 10,
                'placeholder' => 'Unlimited',
            ],
            'constraints' => [
                new Assert\Range(['min' => 0, 'max' => 1600]),
            ],
        ]);
        $builder->add('memoryQuota', IntegerType::class, [
            'required' => false,
            'label' => 'Memory Quota (MB)',
            'attr' => [
                'class' => 'form-control form-control-lg',
                'min' => 0,
                'max' => 1048576,
                'step' => 128,
                'placeholder' => 'Unlimited',
            ],
            'constraints' => [
                new Assert\Range(['min' => 0, 'max' => 1048576]),
            ],
        ]);
        $builder->add('diskQuotaMb', IntegerType::class, [
            'required' => false,
            'label' => 'Disk Quota (MB)',
            'attr' => [
                'class' => 'form-control form-control-lg',
                'min' => 0,
                'max' => 10485760,
                'step' => 256,
                'placeholder' => 'Unlimited (soft warning only without XFS project quotas)',
            ],
            'constraints' => [
                new Assert\Range(['min' => 0, 'max' => 10485760]),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // CSRF is rendered explicitly in the twig template + checked via
        // isCsrfTokenValid('default', ...) in the controller; let the form
        // builder stay out of that codepath.
        $resolver->setDefaults(['data_class' => null, 'csrf_protection' => false]);
    }

    public function getName(): string
    {
        return 'clp_site_resource_quota';
    }
}
