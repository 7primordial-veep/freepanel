<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Finder\Finder;
use App\Validator\Constraints as AppAssert;
class SiteNewPythonType extends AbstractType
{
    private const USR_BIN_DIRECTORY = "/usr/bin/";
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("domainName", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "www.domain.com"], "label" => "Domain Name", "constraints" => [new Assert\NotBlank(), new AppAssert\DomainName(), new AppAssert\UniqueDomainName()]]);
        $pythonVersionChoices = $this->getPythonVersionChoices();
        $builder->add("pythonVersion", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Python Version", "choices" => $pythonVersionChoices]);
        $builder->add("port", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "App Port"]);
        $builder->add("siteUser", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "site-user"], "label" => "Site User", "constraints" => [new Assert\NotBlank(), new Assert\Regex("/^[a-z][-a-z0-9_]+\$/iu"), new Assert\Length(["min" => 3, "max" => 32]), new AppAssert\UniqueSystemUser()]]);
        $builder->add("siteUserPassword", TextType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-control form-control-lg"], "label" => "Site User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8, "max" => 200])]]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $data["domainName"] = true === isset($data["domainName"]) ? strtolower($data["domainName"]) : '';
            $data["siteUser"] = true === isset($data["siteUser"]) ? strtolower($data["siteUser"]) : '';
            $event->setData($data);
        });
    }
    private function getPythonVersionChoices() : array
    {
        $pythonVersionChoices = [];
        $finder = new Finder();
        $finder->files();
        $finder->name(["python*"]);
        $finder->in(self::USR_BIN_DIRECTORY);
        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $pythonVersion = trim(str_replace("python", '', $file->getFilename()));
                if (!(false == empty($pythonVersion) && true === is_numeric($pythonVersion) && false !== strpos($pythonVersion, "."))) {
                    continue;
                }
                $pythonVersionChoices[sprintf("Python %s", $pythonVersion)] = $pythonVersion;
            }
        }
        arsort($pythonVersionChoices, SORT_NATURAL);
        return $pythonVersionChoices;
    }
    public function getName() : string
    {
        return "clp_site_new_python";
    }
}