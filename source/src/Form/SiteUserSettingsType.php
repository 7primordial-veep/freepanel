<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints as Assert;
class SiteUserSettingsType extends AbstractType
{
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_LENGTH = 100;
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("siteUser", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg"], "disabled" => true, "label" => "Site User"]);
        $builder->add("password", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "****************"], "label" => "Password", "constraints" => [new Assert\Length(["min" => self::PASSWORD_MIN_LENGTH, "max" => self::PASSWORD_MAX_LENGTH])]]);
        $builder->add("sshKeys", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5], "label" => "SSH Keys"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (true === isset($data["password"]) && false === empty($data["password"])) {
                $password = str_replace(["\\r\\n", "\\n"], ['', ''], $data["password"]);
                $data["password"] = $password;
            }
            $event->setData($data);
        });
    }
    public function getName() : string
    {
        return "clp_site_user_settings";
    }
}