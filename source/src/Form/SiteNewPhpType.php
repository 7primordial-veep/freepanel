<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\VhostTemplateRepository as VhostTemplateEntityRepository;
use App\Entity\VhostTemplate as VhostTemplateEntity;
use App\Validator\Constraints as AppAssert;
class SiteNewPhpType extends AbstractType
{
    private const IGNORED_TEMPLATES = ["nodejs", "generic", "python", "reverseproxy", "static"];
    private const PHP_DIRECTORY = "/etc/php/";
    private VhostTemplateEntityRepository $vhostTemplateEntityRepository;
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->vhostTemplateEntityRepository = $entityManager->getRepository(VhostTemplateEntity::class);
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $vhostTemplateEntities = $this->vhostTemplateEntityRepository->findBy([], ["name" => "ASC"]);
        $applicationChoices = ["Generic" => "Generic"];
        $applicationAttributes = [];
        foreach ($vhostTemplateEntities as $vhostTemplateEntity) {
            $name = $vhostTemplateEntity->getName();
            $phpVersion = $vhostTemplateEntity->getPhpVersion();
            if (!(false === empty($name) && false === in_array(strtolower($name), self::IGNORED_TEMPLATES))) {
                continue;
            }
            $applicationChoices[$name] = $name;
            $applicationAttributes[$name] = ["data-php-version" => $phpVersion];
        }
        $builder->add("application", ChoiceType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-select form-select-lg"], "label" => "Application", "choices" => $applicationChoices, "choice_attr" => $applicationAttributes, "data" => "Generic"]);
        $builder->add("domainName", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "www.domain.com"], "label" => "Domain Name", "constraints" => [new Assert\NotBlank(), new AppAssert\DomainName(), new AppAssert\UniqueDomainName()]]);
        $phpVersionChoices = $this->getPhpVersionChoices();
        $builder->add("phpVersion", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "PHP Version", "choices" => $phpVersionChoices]);
        $builder->add("siteUser", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "site-user"], "label" => "Site User", "constraints" => [new Assert\NotBlank(), new Assert\Regex("/^[a-z][-a-z0-9_]+\$/iu"), new Assert\Length(["min" => 3, "max" => 32]), new AppAssert\UniqueSystemUser()]]);
        $builder->add("siteUserPassword", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Site User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8, "max" => 200])]]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["domainName"] = true === isset($data["domainName"]) ? strtolower($data["domainName"]) : '';
            $data["siteUser"] = true === isset($data["siteUser"]) ? strtolower($data["siteUser"]) : '';
            $event->setData($data);
        });
    }
    private function getPhpVersionChoices() : array
    {
        $phpVersionChoices = [];
        foreach (new \DirectoryIterator(self::PHP_DIRECTORY) as $fileInfo) {
            if (!(false === $fileInfo->isDot())) {
                continue;
            }
            $phpVersion = $fileInfo->getBasename();
            if (!(true === is_float($phpVersion + 0))) {
                continue;
            }
            $phpVersionChoices[sprintf("PHP %s", $phpVersion)] = $phpVersion;
        }
        arsort($phpVersionChoices);
        return $phpVersionChoices;
    }
    public function getName() : string
    {
        return "clp_site_new_php";
    }
}