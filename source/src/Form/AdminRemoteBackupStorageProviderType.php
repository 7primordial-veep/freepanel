<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use App\Backup\StorageProvider;
class AdminRemoteBackupStorageProviderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $storageProvideChoices = ["Amazon S3" => StorageProvider::AMAZON_S3, "Wasabi" => StorageProvider::WASABI, "Digital Ocean Spaces" => StorageProvider::DIGITAL_OCEAN_SPACES, "Dropbox" => StorageProvider::DROPBOX, "Google Drive" => StorageProvider::GOOGLE_DRIVE, "SFTP" => StorageProvider::SFTP, "Custom Rclone Config" => StorageProvider::CUSTOM_RCLONE];
        $builder->add("storageProvider", ChoiceType::class, ["required" => true, "placeholder" => "Select Storage Provider", "attr" => ["class" => "form-select form-select-lg"], "label" => "Storage Provider", "choices" => $storageProvideChoices]);
    }
    public function getName() : string
    {
        return "clp_admin_remote_backup_storage_provider";
    }
}