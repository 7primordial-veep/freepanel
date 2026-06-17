<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\DatabaseUser as DatabaseUserEntity;
class SiteDatabaseUserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $databaseUserEntity = $options["data"];
        $databaseEntity = $databaseUserEntity->getDatabase();
        $builder->add("userName", TextType::class, ["required" => false, "mapped" => true, "disabled" => "true", "attr" => ["class" => "form-control form-control-lg"], "label" => "Database User Name"]);
        $builder->add("password", TextType::class, ["required" => true, "mapped" => true, "attr" => ["class" => "form-control form-control-lg"], "label" => "Database User Password", "constraints" => [new Assert\NotBlank(), new Assert\Length(["min" => 8]), new Assert\Length(["max" => 40])]]);
        $permissionChoices = ["Read and Write" => DatabaseUserEntity::PERMISSIONS_READ_WRITE, "Read Only" => DatabaseUserEntity::PERMISSIONS_READ_ONLY];
        $builder->add("permissions", ChoiceType::class, ["required" => true, "attr" => ["class" => "form-select form-select-lg"], "label" => "Permissions", "choices" => $permissionChoices]);
        $builder->add("databaseName", TextType::class, ["required" => false, "mapped" => false, "disabled" => true, "disabled" => "true", "attr" => ["class" => "form-control form-control-lg"], "label" => "Database", "data" => $databaseEntity->getName()]);
    }
    public function getName() : string
    {
        return "clp_site_database_user_edit";
    }
}