<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SiteSecurityHeadersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('cspPreset', ChoiceType::class, [
                'label' => 'Content Security Policy',
                'choices' => [
                    'Off' => 'off',
                    'Strict' => 'strict',
                    'Relaxed' => 'relaxed',
                    'Report-Only (loose)' => 'report-only',
                    'Custom' => 'custom',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('cspCustom', TextareaType::class, [
                'label' => 'Custom CSP value',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2, 'placeholder' => "default-src 'self'"],
            ])
            ->add('permissionsPreset', ChoiceType::class, [
                'label' => 'Permissions-Policy',
                'choices' => [
                    'Off' => 'off',
                    'Strict (deny all)' => 'strict',
                    'Relaxed (self only)' => 'relaxed',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('frameOptions', ChoiceType::class, [
                'label' => 'X-Frame-Options',
                'choices' => [
                    'Off' => 'off',
                    'SAMEORIGIN' => 'SAMEORIGIN',
                    'DENY' => 'DENY',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('referrerPolicy', ChoiceType::class, [
                'label' => 'Referrer-Policy',
                'choices' => [
                    'Off' => 'off',
                    'no-referrer' => 'no-referrer',
                    'same-origin' => 'same-origin',
                    'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('nosniff', CheckboxType::class, [
                'label' => 'Send X-Content-Type-Options: nosniff',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('hsts', CheckboxType::class, [
                'label' => 'Enable HSTS (Strict-Transport-Security)',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('hstsMaxAge', IntegerType::class, [
                'label' => 'HSTS max-age (seconds)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('hstsIncludeSubdomains', CheckboxType::class, [
                'label' => 'includeSubDomains',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('hstsPreload', CheckboxType::class, [
                'label' => 'preload',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
        ]);
    }
}
