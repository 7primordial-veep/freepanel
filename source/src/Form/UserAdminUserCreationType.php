<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TimezoneRepository;
use App\Entity\User;
use App\Entity\Timezone;
class UserAdminUserCreationType extends AbstractType
{
    private TimezoneRepository $timezoneRepository;
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->timezoneRepository = $entityManager->getRepository(Timezone::class);
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $user = $options["data"];
        $user->setPassword('');
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["firstName"] = true === isset($data["firstName"]) ? ucfirst($data["firstName"]) : '';
            $data["lastName"] = true === isset($data["lastName"]) ? ucfirst($data["lastName"]) : '';
            $event->setData($data);
        });
        $builder->add("userName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "john.doe"], "label" => "User Name"]);
        $builder->add("email", EmailType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "john@doe.com"], "label" => "E-Mail"]);
        $builder->add("firstName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "John"], "label" => "First Name"]);
        $builder->add("lastName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "Doe"], "label" => "Last Name"]);
        $builder->add("plainPassword", PasswordType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "********************", "autocomplete" => "new-password"], "label" => "Password", "empty_data" => '', "constraints" => [new Assert\Length(["min" => User::PASSWORD_MIN_LENGTH, "max" => User::PASSWORD_MAX_LENGTH])]]);
        $timezoneRepository = $this->timezoneRepository;
        $builder->add("timezone", EntityType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Timezone", "class" => Timezone::class, "query_builder" => function ($timezoneRepository) {
            return $timezoneRepository->createQueryBuilder("t")->orderBy("t.id", "ASC");
        }, "choice_label" => "name"]);
        $builder->add("acceptLicenseTermsPrivacyPolicy", CheckboxType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-check-input"], "label" => "Accept License Terms and Privacy Policy"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["userName"] = true === isset($data["userName"]) ? strtolower($data["userName"]) : '';
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