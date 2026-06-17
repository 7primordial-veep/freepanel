<?php

namespace App\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TimezoneRepository;
use App\Entity\Timezone;
class AdminInstanceSettingsType extends AbstractType
{
    private TimezoneRepository $timezoneRepository;
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->timezoneRepository = $entityManager->getRepository(Timezone::class);
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $timezoneRepository = $this->timezoneRepository;
        $builder->add("timezone", EntityType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Timezone", "class" => Timezone::class, "query_builder" => function ($timezoneRepository) {
            return $timezoneRepository->createQueryBuilder("t")->orderBy("t.id", "ASC");
        }, "choice_label" => "name"]);
    }
    public function getName() : string
    {
        return "clp_admin_instance_settings";
    }
}