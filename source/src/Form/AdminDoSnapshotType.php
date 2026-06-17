<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
class AdminDoSnapshotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder->add("name", TextType::class, ["required" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Snapshot Name"]);
    }
    public function getName() : string
    {
        return "clp_admin_do_snapshot";
    }
}