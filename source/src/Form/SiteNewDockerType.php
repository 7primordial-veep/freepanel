<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;

class SiteNewDockerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('domainName', TextType::class, [
            'required' => true,
            'mapped' => false,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => 'www.domain.com'],
            'label' => 'Domain Name',
            'constraints' => [new Assert\NotBlank(), new AppAssert\DomainName(), new AppAssert\UniqueDomainName()],
        ]);
        $builder->add('dockerImage', TextType::class, [
            'required' => true,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => 'nginx:latest'],
            'label' => 'Docker Image',
            'constraints' => [new Assert\NotBlank(), new Assert\Length(['min' => 1, 'max' => 255])],
            // ponytail: TODO real image-ref validator
        ]);
        $builder->add('dockerPort', IntegerType::class, [
            'required' => true,
            'data' => 8080,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => '8080'],
            'label' => 'Host Port',
            'constraints' => [new Assert\NotBlank(), new Assert\Range(['min' => 1024, 'max' => 65535])],
        ]);
        $builder->add('dockerEnv', TextType::class, [
            'required' => false,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => 'KEY1=value1,KEY2=value2'],
            'label' => 'Environment Variables (comma-separated KEY=value)',
            // ponytail: TODO JSON or repeated-row UI
        ]);
        $builder->add('siteUser', TextType::class, [
            'required' => true,
            'attr' => ['class' => 'form-control form-control-lg', 'placeholder' => 'site-user'],
            'label' => 'Site User',
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Regex('/^[a-z][-a-z0-9_]+$/iu'),
                new Assert\Length(['min' => 3, 'max' => 32]),
                new AppAssert\UniqueSystemUser(),
            ],
        ]);
        $builder->add('siteUserPassword', TextType::class, [
            'required' => true,
            'attr' => ['class' => 'form-control form-control-lg'],
            'label' => 'Site User Password',
            'constraints' => [new Assert\NotBlank(), new Assert\Length(['min' => 8, 'max' => 200])],
        ]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data['domainName'] = isset($data['domainName']) ? strtolower($data['domainName']) : '';
            $data['siteUser'] = isset($data['siteUser']) ? strtolower($data['siteUser']) : '';
            $event->setData($data);
        });
    }

    public function getName(): string
    {
        return 'clp_site_new_docker';
    }
}
