<?php

namespace App\Form;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;
class SitePythonSettingsType extends AbstractType
{
    private const USR_BIN_DIRECTORY = "/usr/bin/";
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $pythonVersionChoices = $this->getPythonVersionChoices();
        $builder->add("pythonVersion", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Python Version", "choices" => $pythonVersionChoices]);
        $builder->add("port", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "App Port"]);
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
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
        $resolver->setDefaults(["data_class" => "App\\Entity\\PythonSettings"]);
    }
    public function getName() : string
    {
        return "clp_site_python_settings";
    }
}