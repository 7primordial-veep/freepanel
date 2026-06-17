<?php

namespace App\System\Command;

use App\System\Command;
use App\Entity\Database as DatabaseEntity;
class ImportDatabaseDumpCommand extends Command
{
    private ?DatabaseEntity $databaseEntity;
    private ?string $file = null;
    private bool $isGzipped = false;
    private ?string $tmpFile = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $databaseEntity = $this->getDatabaseEntity();
            $databaseServerEntity = $databaseEntity->getDatabaseServer();
            $file = $this->getFile();
            $isGzipped = "gz" == substr($file, -2);
            $tmpFile = $this->getTmpFile();
            $tmpFileContent = ["[client]", sprintf("password=%s", $databaseServerEntity->getDecryptedPassword())];
            file_put_contents($tmpFile, implode(PHP_EOL, $tmpFileContent));
            chmod($tmpFile, 0400);
            if (true === $isGzipped) {
                $this->command = sprintf("/usr/bin/sudo /usr/bin/setfacl -m u:www-data:--- /usr/bin/sh;/usr/bin/sudo chown www-data:www-data %s;/usr/bin/sudo /bin/bash -c \"/usr/bin/sudo /usr/bin/gunzip < %s\" | /usr/bin/sudo -u www-data /bin/bash -c \"/usr/bin/mysql --defaults-extra-file=%s -f -h%s -u%s -P%s %s\"", escapeshellarg($tmpFile), escapeshellarg($file), escapeshellarg($tmpFile), escapeshellarg($databaseServerEntity->getHost()), escapeshellarg($databaseServerEntity->getUserName()), escapeshellarg($databaseServerEntity->getPort()), escapeshellarg($databaseEntity->getName()));
            } else {
                $this->command = sprintf("/usr/bin/sudo /usr/bin/setfacl -m u:www-data:--- /usr/bin/sh;/usr/bin/sudo chown www-data:www-data %s;/usr/bin/sudo /bin/bash -c \"/usr/bin/cat %s\" | /usr/bin/sudo -u www-data /bin/bash -c \"/usr/bin/mysql --defaults-extra-file=%s -f -h%s -u%s -P%s %s\"", escapeshellarg($tmpFile), escapeshellarg($file), escapeshellarg($tmpFile), escapeshellarg($databaseServerEntity->getHost()), escapeshellarg($databaseServerEntity->getUserName()), escapeshellarg($databaseServerEntity->getPort()), escapeshellarg($databaseEntity->getName()));
            }
        }
        return $this->command;
    }
    private function getTmpFile() : ?string
    {
        if (true === is_null($this->tmpFile)) {
            $this->tmpFile = sprintf("/tmp/.clp_tmp_%s", sha1(uniqid(mt_rand(), true)));
        }
        return $this->tmpFile;
    }
    public function isSuccessful() : bool
    {
        return true;
    }
    public function setDatabaseEntity(DatabaseEntity $databaseEntity)
    {
        $this->databaseEntity = $databaseEntity;
    }
    public function getDatabaseEntity() : ?DatabaseEntity
    {
        return $this->databaseEntity;
    }
    public function setFile(string $file) : void
    {
        $this->file = $file;
    }
    public function getFile() : ?string
    {
        return $this->file;
    }
    public function setGzipped(bool $flag) : void
    {
        $this->isGzipped = $flag;
    }
    public function isGzipped() : bool
    {
        return $this->isGzipped;
    }
    public function __destruct()
    {
        $tmpFile = $this->getTmpFile();
        $deleteTmpFileCommand = sprintf("/usr/bin/sudo -u www-data /usr/bin/rm -f %s", $tmpFile);
        @system($deleteTmpFileCommand);
    }
}