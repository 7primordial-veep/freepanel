<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
class SiteSshUserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("userName", TextType::class, ["required" => false, "mapped" => true, "attr" => ["class" => "form-control form-control-lg", "readonly" => "readonly"], "label" => "User Name"]);
        $builder->add("password", TextType::class, ["required" => false, "mapped" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Password"]);
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
        return "clp_site_ssh_user";
    }
}