<?php

namespace App\Backup;

class StorageProvider
{
    public const AMAZON_S3 = "amazon-s3";
    public const GOOGLE_DRIVE = "google-drive";
    public const DIGITAL_OCEAN_SPACES = "digital-ocean-spaces";
    public const DROPBOX = "dropbox";
    public const SFTP = "sftp";
    public const WASABI = "wasabi";
    public const CUSTOM_RCLONE = "custom-rclone";
}