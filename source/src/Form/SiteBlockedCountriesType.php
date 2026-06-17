<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

class SiteBlockedCountriesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('blockedCountries', TextareaType::class, [
            'required' => false,
            'label' => 'Blocked Countries',
            'attr' => [
                'rows' => 2,
                'placeholder' => 'CN, RU, KP',
            ],
            'help' => 'Comma-separated ISO 3166-1 alpha-2 country codes (e.g. CN, RU). Requires nginx GeoIP module.',
            'constraints' => [
                new Regex([
                    'pattern' => '/^\s*([A-Za-z]{2})(\s*,\s*[A-Za-z]{2})*\s*$/',
                    'message' => 'Must be a comma-separated list of 2-letter country codes.',
                    'match' => true,
                ]),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'site_blocked_countries';
    }
}
