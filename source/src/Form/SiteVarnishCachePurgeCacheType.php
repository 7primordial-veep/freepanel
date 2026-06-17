<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class SiteVarnishCachePurgeCacheType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $site = $options["data"]["site"] ?? null;
        $placeholderVale = '';
        if (false === is_null($site)) {
            $domainName = $site->getDomainName();
            $placeholderVale = sprintf("https://%s/example-site", $domainName);
        }
        $builder->add("value", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => $placeholderVale]]);
    }
    public function getName() : string
    {
        return "clp_site_varnish_cache_purge_cache";
    }
}