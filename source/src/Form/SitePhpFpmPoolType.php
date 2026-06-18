<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class SitePhpFpmPoolType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder
            ->add('pm', ChoiceType::class, [
                'label'    => 'Process Manager (pm)',
                'required' => true,
                'choices'  => [
                    'dynamic'  => 'dynamic',
                    'static'   => 'static',
                    'ondemand' => 'ondemand',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Choice(['choices' => ['dynamic', 'static', 'ondemand']]),
                ],
            ])
            ->add('pmMaxChildren', IntegerType::class, [
                'label'       => 'pm.max_children',
                'required'    => true,
                'attr'        => ['min' => 1, 'max' => 200],
                'constraints' => [new NotBlank(), new Range(['min' => 1, 'max' => 200])],
            ])
            ->add('pmStartServers', IntegerType::class, [
                'label'       => 'pm.start_servers',
                'required'    => true,
                'attr'        => ['min' => 1, 'max' => 50],
                'constraints' => [new NotBlank(), new Range(['min' => 1, 'max' => 50])],
            ])
            ->add('pmMinSpareServers', IntegerType::class, [
                'label'       => 'pm.min_spare_servers',
                'required'    => true,
                'attr'        => ['min' => 1, 'max' => 50],
                'constraints' => [new NotBlank(), new Range(['min' => 1, 'max' => 50])],
            ])
            ->add('pmMaxSpareServers', IntegerType::class, [
                'label'       => 'pm.max_spare_servers',
                'required'    => true,
                'attr'        => ['min' => 1, 'max' => 50],
                'constraints' => [new NotBlank(), new Range(['min' => 1, 'max' => 50])],
            ])
            ->add('pmMaxRequests', IntegerType::class, [
                'label'       => 'pm.max_requests',
                'required'    => true,
                'attr'        => ['min' => 0, 'max' => 100000],
                'constraints' => [new NotBlank(), new Range(['min' => 0, 'max' => 100000])],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Save',
                'attr'  => ['class' => 'btn btn-blue'],
            ]);
    }
}
