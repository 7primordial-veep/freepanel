<?php

namespace App\Backup\Restore;

use App\Backup\Rclone;
use App\System\CommandExecutor;
use App\System\Command\RestoreExtractCommand;
use App\Entity\Manager\SiteManager;

class BackupRestorer
{
    private Rclone $rclone;
    private CommandExecutor $exec;
    private SiteManager $siteManager;

    public function __construct(Rclone $rclone, CommandExecutor $exec, SiteManager $siteManager)
    {
        $this->rclone = $rclone;
        $this->exec = $exec;
        $this->siteManager = $siteManager;
    }

    /**
     * Restore a backup object (path within the configured remote backup root) into the
     * target site's home dir. $backupObject is the rclone path including 'backup.tar.gz'
     * or 'backup.tar'. $targetDomain MUST already exist as a site — we do NOT auto-create.
     *
     * @return array{ok:bool, output:string}
     */
    public function restore(string $backupObject, string $targetDomain): array
    {
        $backupObject = trim($backupObject);
        if ('' === $backupObject) {
            return ['ok' => false, 'output' => 'backup_object is required'];
        }
        if (false !== strpos($backupObject, '..')) {
            return ['ok' => false, 'output' => 'invalid backup_object path'];
        }

        $site = $this->siteManager->findOneByDomainName($targetDomain);
        if (null === $site) {
            return ['ok' => false, 'output' => 'Target site does not exist: ' . $targetDomain];
        }

        $user = (string) $site->getUser();
        if ('' === $user || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user)) {
            return ['ok' => false, 'output' => 'Target site has invalid system user.'];
        }

        $tmp = sprintf('/home/%s/tmp/restore-%d.tar', $user, time());

        try {
            $this->rclone->copy('remote:' . $backupObject, $tmp);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => 'rclone copy failed: ' . $e->getMessage()];
        }

        $cmd = new RestoreExtractCommand();
        $cmd->setTargetUser($user);
        $cmd->setArchive($tmp);

        try {
            $this->exec->execute($cmd, 7200);
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'output' => 'extract failed: ' . $e->getMessage()];
        }

        @unlink($tmp);

        return [
            'ok' => true,
            'output' => 'restored from ' . $backupObject . ' to /home/' . $user . '/',
        ];
    }
}
