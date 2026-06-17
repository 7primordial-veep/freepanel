<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\DatabaseRepository;
use App\Entity\Database as DatabaseEntity;
use App\Entity\DatabaseUser as DatabaseUserEntity;
class SiteDatabaseUserType extends AbstractType
{
    private DatabaseRepository $databaseRepository;
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->databaseRepository = $entityManager->getRepository(DatabaseEntity::class);
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $databaseUserEntity = $options["data"];
        $builder->add("userName", TextType::class, ["required" => true, "mapped" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Database User Name"]);
        $builder->add("password", TextType::class, ["required" => true, "mapped" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Database User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8]), new Assert\Length(["max" => 40])]]);
        $permissionChoices = ["Read and Write" => DatabaseUserEntity::PERMISSIONS_READ_WRITE, "Read Only" => DatabaseUserEntity::PERMISSIONS_READ_ONLY];
        $builder->add("permissions", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Permissions", "choices" => $permissionChoices]);
        $siteEntity = $databaseUserEntity->getSite();
        $databaseRepository = $this->databaseRepository;
        $builder->add("database", EntityType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Database", "class" => DatabaseEntity::class, "query_builder" => function ($databaseRepository) use($siteEntity) {
            $queryBuilder = $databaseRepository->createQueryBuilder("d");
            $queryBuilder->where("d.site = :site");
            $queryBuilder->setParameter("site", $siteEntity);
            $queryBuilder->orderBy("d.name", "ASC");
            return $queryBuilder;
        }, "choice_label" => "name"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["userName"] = true === isset($data["userName"]) ? strtolower($data["userName"]) : '';
            $event->setData($data);
        });
    }
    public function getName() : string
    {
        return "clp_site_database_user";
    }
}