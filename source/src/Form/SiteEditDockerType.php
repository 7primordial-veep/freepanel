<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class SiteEditDockerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('dockerImage', TextType::class, [
            'required' => true,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => 'nginx:latest'],
            'label' => 'Docker Image',
            'constraints' => [new Assert\NotBlank(), new Assert\Length(['min' => 1, 'max' => 255])],
        ]);
        $builder->add('dockerPort', IntegerType::class, [
            'required' => true,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => '8080'],
            'label' => 'Host Port',
            'constraints' => [new Assert\NotBlank(), new Assert\Range(['min' => 1024, 'max' => 65535])],
        ]);
        $builder->add('dockerEnvRaw', TextareaType::class, [
            'required' => false,
            'attr' => [
                'class' => 'form-control form-control-lg',
                'rows' => 6,
                'placeholder' => "KEY1=value1,KEY2=value2\nDB_HOST=127.0.0.1,DB_PORT=3306",
            ],
            'label' => 'Environment Variables (multi-line; comma-separated KEY=VALUE on each line)',
        ]);
        $builder->add('dockerVolumesRaw', TextareaType::class, [
            'required' => false,
            'attr' => [
                'class' => 'form-control form-control-lg',
                'rows' => 6,
                'placeholder' => "/host/path:/container/path\n/var/www/data:/data",
            ],
            'label' => 'Volumes (one host:container mapping per line)',
        ]);
        $builder->add('submit', SubmitType::class, [
            'label' => 'Save',
            'attr' => ['class' => 'btn btn-primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }

    public function getName(): string
    {
        return 'clp_site_edit_docker';
    }
}
