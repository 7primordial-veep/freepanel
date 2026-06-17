<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
class SiteNewWordPressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("domainName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "www.domain.com"], "label" => "Domain Name", "constraints" => [new Assert\NotBlank(), new AppAssert\DomainName(), new AppAssert\UniqueDomainName()]]);
        $builder->add("siteTitle", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "Site Title"], "label" => "Site Title", "data" => "Site Title", "constraints" => [new Assert\NotBlank()]]);
        $builder->add("siteUser", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "site-user"], "label" => "Site User", "constraints" => [new Assert\NotBlank(), new Assert\Regex("/^[a-z][-a-z0-9_]+\$/iu"), new Assert\Length(["min" => 3, "max" => 32]), new AppAssert\UniqueSystemUser()]]);
        $builder->add("siteUserPassword", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Site User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8, "max" => 200])]]);
        $builder->add("adminUserName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Admin User Name", "data" => "admin", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 3])]]);
        $builder->add("adminPassword", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Admin Password", "constraints" => [new Assert\NotBlank()]]);
        $builder->add("adminEmail", EmailType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "john.doe@domain.com"], "label" => "Admin E-Mail", "constraints" => [new Assert\NotBlank(), new Assert\Email()]]);
        $multiSiteChoices = ["No" => false, "Yes" => true];
        $builder->add("multiSite", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Multisite", "choices" => $multiSiteChoices, "data" => false]);
        $localeChoices = [
            "English (US)" => "en_US",
            "English (UK)" => "en_GB",
            "English (Australia)" => "en_AU",
            "English (Canada)" => "en_CA",
            "German" => "de_DE",
            "German (Austria)" => "de_AT",
            "German (Switzerland)" => "de_CH",
            "French" => "fr_FR",
            "French (Canada)" => "fr_CA",
            "Spanish (Spain)" => "es_ES",
            "Spanish (Mexico)" => "es_MX",
            "Spanish (Argentina)" => "es_AR",
            "Portuguese (Portugal)" => "pt_PT",
            "Portuguese (Brazil)" => "pt_BR",
            "Italian" => "it_IT",
            "Dutch" => "nl_NL",
            "Polish" => "pl_PL",
            "Czech" => "cs_CZ",
            "Swedish" => "sv_SE",
            "Norwegian (Bokmal)" => "nb_NO",
            "Danish" => "da_DK",
            "Finnish" => "fi",
            "Russian" => "ru_RU",
            "Ukrainian" => "uk",
            "Turkish" => "tr_TR",
            "Greek" => "el",
            "Hungarian" => "hu_HU",
            "Romanian" => "ro_RO",
            "Hebrew" => "he_IL",
            "Arabic" => "ar",
            "Persian" => "fa_IR",
            "Hindi" => "hi_IN",
            "Chinese (Simplified)" => "zh_CN",
            "Chinese (Traditional)" => "zh_TW",
            "Japanese" => "ja",
            "Korean" => "ko_KR",
            "Vietnamese" => "vi",
            "Thai" => "th",
            "Indonesian" => "id_ID",
        ];
        $builder->add("locale", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Site Language", "choices" => $localeChoices, "data" => "en_US", "constraints" => [new Assert\NotBlank(), new Assert\Regex("/^[a-zA-Z]{2,3}(_[a-zA-Z0-9]{2,4})?\$/")]]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["domainName"] = true === isset($data["domainName"]) ? strtolower($data["domainName"]) : '';
            $data["domainName"] = str_replace(["https://", "http://"], ['', ''], $data["domainName"]);
            $event->setData($data);
        });
    }
    public function getName() : string
    {
        return "clp_site_new_wordpress";
    }
}