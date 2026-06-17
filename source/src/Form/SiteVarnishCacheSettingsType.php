<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
class SiteVarnishCacheSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("isEnabled", CheckboxType::class, ["required" => false, "attr" => ["class" => "form-check-input"], "label" => "Varnish Cache"]);
        $builder->add("server", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Varnish Server"]);
        $builder->add("cacheTagPrefix", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Cache Tag Prefix"]);
        $builder->add("cacheLifetime", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Cache Lifetime"]);
        $builder->add("excludedParams", TextType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "__SID,noCache"], "label" => "Excluded Params"]);
        $excludesPlaceholder = ["^/admin/", "/checkout/", "t.php"];
        $builder->add("excludes", TextareaType::class, ["required" => false, "attr" => ["class" => "form-control form-control-lg", "rows" => 5, "placeholder" => implode(PHP_EOL, $excludesPlaceholder)], "label" => "Excludes"]);
    }
    public function getName() : string
    {
        return "clp_site_varnish_cache_settings";
    }
}