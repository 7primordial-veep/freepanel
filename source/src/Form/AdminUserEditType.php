<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Repository\TimezoneRepository as TimezoneEntityRepository;
use App\Entity\User as UserEntity;
use App\Entity\Timezone as TimezoneEntity;
use App\Validator\Constraints\UserSites as UserSitesConstraint;
class AdminUserEditType extends AbstractType
{
    private SiteEntityManager $siteEntityManager;
    private TimezoneEntityRepository $timezoneEntityRepository;
    public function __construct(SiteEntityManager $siteEntityManager, EntityManagerInterface $entityManager)
    {
        $this->siteEntityManager = $siteEntityManager;
        $this->timezoneEntityRepository = $entityManager->getRepository(TimezoneEntity::class);
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $userEntity = $options["data"];
        $builder->add("userName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "User Name"]);
        $builder->add("email", EmailType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "E-Mail"]);
        $builder->add("firstName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "First Name"]);
        $builder->add("lastName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Last Name"]);
        $builder->add("plainPassword", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Password", "empty_data" => null, "constraints" => [new Assert\Length(["min" => UserEntity::PASSWORD_MIN_LENGTH, "max" => UserEntity::PASSWORD_MAX_LENGTH])]]);
        $statusChoices = ["Active" => UserEntity::STATUS_ACTIVE, "Not Active" => UserEntity::STATUS_NOT_ACTIVE];
        $builder->add("status", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Status", "choices" => $statusChoices]);
        $roleChoices = ["Admin" => UserEntity::ROLE_ADMIN, "User" => UserEntity::ROLE_USER, "Site Manager" => UserEntity::ROLE_SITE_MANAGER];
        $builder->add("role", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Role", "choices" => $roleChoices]);
        $timezoneEntityRepository = $this->timezoneEntityRepository;
        $builder->add("timezone", EntityType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Timezone", "class" => TimezoneEntity::class, "query_builder" => function ($timezoneEntityRepository) {
            return $timezoneEntityRepository->createQueryBuilder("t")->orderBy("t.id", "ASC");
        }, "choice_label" => "name"]);
        $sites = $userEntity->getSites();
        $sitesValue = [];
        if (count($sites)) {
            foreach ($sites as $site) {
                $sitesValue[] = $site->getDomainName();
            }
        }
        $builder->add("sites", TextType::class, ["label" => "Sites", "attr" => ["class" => "d-none"], "mapped" => false, "required" => false, "data" => implode(",", $sitesValue), "constraints" => [new UserSitesConstraint()]]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use($userEntity) {
            $data = $event->getData();
            $data["userName"] = true === isset($data["userName"]) ? strtolower($data["userName"]) : '';
            $data["firstName"] = true === isset($data["firstName"]) ? ucfirst($data["firstName"]) : '';
            $data["lastName"] = true === isset($data["lastName"]) ? ucfirst($data["lastName"]) : '';
            $event->setData($data);
        });
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
        $resolver->setDefaults(["data_class" => "App\\Entity\\User"]);
    }
    public function getName() : string
    {
        return "clp_admin_user";
    }
}