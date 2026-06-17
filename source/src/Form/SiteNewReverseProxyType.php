<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
class SiteNewReverseProxyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("domainName", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "www.domain.com"], "label" => "Domain Name", "constraints" => [new Assert\NotBlank(), new AppAssert\DomainName(), new AppAssert\UniqueDomainName()]]);
        $builder->add("reverseProxyUrl", TextType::class, ["required" => true, "data" => "http://127.0.0.1:8000", "attr" => ["class" => "form-control form-control-lg", "placeholder" => "http://127.0.0.1:8000"], "label" => "Reverse Proxy Url"]);
        $builder->add("siteUser", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "site-user"], "label" => "Site User", "constraints" => [new Assert\NotBlank(), new Assert\Regex("/^[a-z][-a-z0-9_]+\$/iu"), new Assert\Length(["min" => 3, "max" => 32]), new AppAssert\UniqueSystemUser()]]);
        $builder->add("siteUserPassword", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Site User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8, "max" => 200])]]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["domainName"] = true === isset($data["domainName"]) ? strtolower($data["domainName"]) : '';
            $data["siteUser"] = true === isset($data["siteUser"]) ? strtolower($data["siteUser"]) : '';
            $event->setData($data);
        });
    }
    public function getName() : string
    {
        return "clp_site_new_reverse_proxy";
    }
}