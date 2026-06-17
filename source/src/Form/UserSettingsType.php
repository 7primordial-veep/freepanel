<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TimezoneRepository;
use App\Entity\User;
use App\Entity\Timezone;
class UserSettingsType extends AbstractType
{
    private TimezoneRepository $timezoneRepository;
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->timezoneRepository = $entityManager->getRepository(Timezone::class);
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $user = $options["data"];
        $builder->add("userName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "disabled" => "disabled"], "label" => "User Name"]);
        $builder->add("email", EmailType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "E-Mail"]);
        $builder->add("firstName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "First Name"]);
        $builder->add("lastName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Last Name"]);
        $builder->add("plainPassword", PasswordType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "********************", "autocomplete" => "new-password"], "label" => "Password", "empty_data" => null, "constraints" => [new Assert\Length(["min" => User::PASSWORD_MIN_LENGTH, "max" => User::PASSWORD_MAX_LENGTH])]]);
        $timezoneRepository = $this->timezoneRepository;
        $builder->add("timezone", EntityType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Timezone", "class" => Timezone::class, "query_builder" => function ($timezoneRepository) {
            return $timezoneRepository->createQueryBuilder("t")->orderBy("t.id", "ASC");
        }, "choice_label" => "name"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use($user) {
            $data = $event->getData();
            $data["userName"] = $user->getUserName();
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
        return "clp_user_settings";
    }
}