<?php

namespace App\System\Command;

use App\System\Command;
use App\Entity\Database as DatabaseEntity;
class CreateDatabaseDumpCommand extends Command
{
    private ?DatabaseEntity $databaseEntity;
    private ?string $file = null;
    private string $mysqlDumpOptions = "--force --opt --single-transaction --quick";
    private ?string $tmpFile = null;
    public function getCommand() : string
    {
        if (!$this->command) {
            $databaseEntity = $this->getDatabaseEntity();
            $databaseServerEntity = $databaseEntity->getDatabaseServer();
            $mysqlDumpOptions = $this->getMySQLDumpOptions();
            $file = $this->getFile();
            $runAsUser = $this->getRunAsUser();
            $compress = "gz" == substr($file, -2) ? true : false;
            $sudo = "/usr/bin/sudo";
            if (false === is_null($runAsUser)) {
                $sudo = sprintf("/usr/bin/sudo -u %s", escapeshellarg($runAsUser));
            }
            $tmpFile = $this->getTmpFile();
            file_put_contents($tmpFile, $databaseServerEntity->getDecryptedPassword());
            chmod($tmpFile, 0400);
            $this->command = sprintf("%s /usr/bin/mysqldump %s -h%s -P%s -u%s -p< %s %s %s | %s /usr/bin/tee %s > /dev/null", $sudo, $mysqlDumpOptions, escapeshellarg($databaseServerEntity->getHost()), escapeshellarg($databaseServerEntity->getPort()), escapeshellarg($databaseServerEntity->getUserName()), $tmpFile, escapeshellarg($databaseEntity->getName()), true === $compress ? sprintf("| %s /bin/gzip", $sudo) : '', $sudo, escapeshellarg($file));
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
    public function setMySQLDumpOptions($mysqlDumpOptions) : void
    {
        $this->mysqlDumpOptions = $mysqlDumpOptions;
    }
    public function getMySQLDumpOptions() : string
    {
        return $this->mysqlDumpOptions;
    }
    public function __destruct()
    {
        $tmpFile = $this->getTmpFile();
        @unlink($tmpFile);
    }
}