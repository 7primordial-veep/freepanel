<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use App\Entity\FirewallRule;
use App\CloudPanel as CloudPanel;
class AdminFirewallRuleEditType extends AbstractType
{
    private RequestStack $requestStack;
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $firewallRule = $options["data"];
        $typeChoices = [];
        $firewallRuleTypes = FirewallRule::TYPES;
        foreach ($firewallRuleTypes as $portRange => $name) {
            $typeChoices[$name] = $portRange;
        }
        $builder->add("type", ChoiceType::class, ["required" => true, "mapped" => false, "attr" => ["class" => "form-select form-select-lg"], "label" => "Type", "choices" => $typeChoices, "data" => $firewallRule->getPortRange()]);
        $builder->add("portRange", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Port Range"]);
        $request = $this->requestStack->getCurrentRequest();
        $myIp = $request->getClientIp();
        $sourceChoices = ["Custom" => '', "Anywhere-IPv4" => "0.0.0.0/0", "Anywhere-IPv6" => "::/0", "My IP" => $myIp];
        $builder->add("sourceChoice", ChoiceType::class, ["required" => false, "mapped" => false, "attr" => ["class" => "form-select form-select-lg"], "label" => "Source", "choices" => $sourceChoices, "data" => $firewallRule->getSource()]);
        $builder->add("source", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg", "placeholder" => "IP or CIDR"], "label" => "Source"]);
        $builder->add("description", TextType::class, ["required" => false, "attr" => ["class" => "form-control", "placeholder" => "John Doe"], "label" => "Description"]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (true === isset($data["portRange"])) {
                $data["portRange"] = array_map("trim", explode("-", $data["portRange"]));
                $data["portRange"] = implode("-", array_map(function ($p) {
                    return (int) $p;
                }, $data["portRange"]));
            }
            $event->setData($data);
        });
    }
    public function setDefaultOptions(OptionsResolverInterface $resolver) : void
    {
        $resolver->setDefaults(["data_class" => "App\\Entity\\FirewallRule"]);
    }
    public function getName() : string
    {
        return "clp_admin_firewall_rule";
    }
}