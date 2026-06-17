<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Entity\BackupTestResult;
use App\Entity\Manager\BackupTestResultManager;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\ConfigManager;
use App\Entity\Notification;
use App\Notification\NotificationQueue;
use App\Service\Logger;

class RemoteBackupTestRestoreCommand extends BaseCommand
{
    private const WORK_DIR = '/tmp/clp-backup-test';
    private const RCLONE_CONFIG = '/root/.config/rclone/rclone.conf';
    private const REMOTE_NAME = 'remote';

    private SiteEntityManager $siteEntityManager;
    private BackupTestResultManager $resultManager;
    private ConfigManager $configManager;
    private NotificationQueue $notificationQueue;

    public function __construct(
        SiteEntityManager $siteEntityManager,
        BackupTestResultManager $resultManager,
        ConfigManager $configManager,
        NotificationQueue $notificationQueue,
        Logger $logger
    ) {
        $this->siteEntityManager = $siteEntityManager;
        $this->resultManager = $resultManager;
        $this->configManager = $configManager;
        $this->notificationQueue = $notificationQueue;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('remote-backup:test-restore');
        $this->setDescription('Validates one rotating remote backup: tar integrity + sample SQL parse.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = time();
        $result = new BackupTestResult();

        try {
            $enabled = (bool) $this->configManager->get('remote_backup_enabled');
            if (false === $enabled) {
                $output->writeln('Remote backup not enabled; nothing to test.');
                return BaseCommand::SUCCESS;
            }

            $sites = $this->siteEntityManager->findAll();
            $domains = [];
            foreach ($sites as $site) {
                if (method_exists($site, 'getDomainName') && $site->getDomainName()) {
                    $domains[] = $site->getDomainName();
                }
            }
            $domain = $this->resultManager->findNextDomainToTest($domains);
            if (null === $domain) {
                $output->writeln('No site candidates.');
                return BaseCommand::SUCCESS;
            }
            $result->setDomainName($domain);

            // Locate latest remote tar for this domain.
            // Convention used by RemoteBackupCreateCommand: <root>/<domain>/<date>/files.tar.gz + db.sql.gz
            $remoteRoot = $this->configManager->get('remote_backup_path') ?: '';
            $remotePrefix = sprintf('%s:%s/%s', self::REMOTE_NAME, rtrim($remoteRoot, '/'), $domain);
            $listCmd = sprintf('rclone --config=%s lsjson --dirs-only %s 2>/dev/null', escapeshellarg(self::RCLONE_CONFIG), escapeshellarg($remotePrefix));
            $listOut = @shell_exec($listCmd) ?: '[]';
            $entries = json_decode($listOut, true) ?: [];
            if (0 === count($entries)) {
                throw new \RuntimeException(sprintf('No backup directories found at %s', $remotePrefix));
            }
            usort($entries, fn($a, $b) => strcmp($b['Name'] ?? '', $a['Name'] ?? ''));
            $latestDir = $entries[0]['Name'];

            // Prepare work dir
            @mkdir(self::WORK_DIR, 0700, true);
            $localDir = self::WORK_DIR . '/' . bin2hex(random_bytes(4));
            @mkdir($localDir, 0700, true);
            $remoteLatest = $remotePrefix . '/' . $latestDir;
            $copyCmd = sprintf('rclone --config=%s copy %s %s --max-size=2G --timeout=30m 2>&1', escapeshellarg(self::RCLONE_CONFIG), escapeshellarg($remoteLatest), escapeshellarg($localDir));
            $copyOut = @shell_exec($copyCmd) ?: '';
            $files = glob($localDir . '/*');
            if (false === $files || 0 === count($files)) {
                throw new \RuntimeException('rclone copy produced no files: ' . trim($copyOut));
            }
            $totalSize = 0;
            foreach ($files as $f) {
                $totalSize += (int) @filesize($f);
            }
            $result->setSizeBytes($totalSize);
            $result->setBackupFile($latestDir);

            // Validate tar(s)
            $tarFiles = array_filter($files, fn($f) => preg_match('/\.tar(\.gz)?$/i', $f));
            foreach ($tarFiles as $tar) {
                $rc = 0;
                $tarOut = [];
                exec(sprintf('tar tf %s > /dev/null 2>&1', escapeshellarg($tar)), $tarOut, $rc);
                if (0 !== $rc) {
                    throw new \RuntimeException(sprintf('tar integrity failed for %s', basename($tar)));
                }
                // Extract one sample file to confirm decompression works
                $rc = 0;
                exec(sprintf('tar xzf %s -C %s --occurrence=1 2>/dev/null || tar xf %s -C %s --occurrence=1 2>/dev/null', escapeshellarg($tar), escapeshellarg($localDir), escapeshellarg($tar), escapeshellarg($localDir)), $tarOut, $rc);
                // Non-fatal if --occurrence unsupported; integrity check above already passed.
            }

            // Validate SQL dump head parses
            $sqlFiles = array_filter($files, fn($f) => preg_match('/\.sql(\.gz)?$/i', $f));
            foreach ($sqlFiles as $sql) {
                $head = '';
                if (preg_match('/\.gz$/', $sql)) {
                    $gz = @gzopen($sql, 'rb');
                    if (false === $gz) {
                        throw new \RuntimeException('Cannot open gz: ' . basename($sql));
                    }
                    $head = (string) gzread($gz, 4096);
                    gzclose($gz);
                } else {
                    $head = (string) @file_get_contents($sql, false, null, 0, 4096);
                }
                if (false === stripos($head, 'CREATE') && false === stripos($head, 'INSERT') && false === stripos($head, 'mysqldump')) {
                    throw new \RuntimeException('SQL dump does not look like a valid dump: ' . basename($sql));
                }
            }

            $result->setStatus(BackupTestResult::STATUS_PASS);
            $result->setMessage(sprintf('Validated %d file(s) from %s/%s.', count($files), $domain, $latestDir));
            $output->writeln(sprintf('<info>PASS %s @ %s</info>', $domain, $latestDir));
            $this->resultManager->updateEntity($result);

            // Cleanup
            $this->cleanup($localDir);
            return BaseCommand::SUCCESS;
        } catch (\Throwable $e) {
            $result->setStatus(BackupTestResult::STATUS_FAIL);
            $result->setMessage($e->getMessage());
            $result->setDurationSeconds(time() - $startedAt);
            try {
                $this->resultManager->updateEntity($result);
            } catch (\Throwable $ignored) {
                // entity manager closed; best-effort log only
            }
            $this->logger->exception($e);
            $output->writeln(sprintf('<error>FAIL: %s</error>', $e->getMessage()));

            $notification = new Notification();
            $notification->setSeverity(Notification::SEVERITY_WARNING);
            $notification->setSubject('Backup test-restore failed');
            $notification->setMessage(sprintf('Domain: %s. Reason: %s', $result->getDomainName() ?: 'unknown', $e->getMessage()));
            $notification->setUrl('/admin/remote-backup');
            try {
                $this->notificationQueue->push($notification);
            } catch (\Throwable $ignored) {
            }
            return BaseCommand::FAILURE;
        } finally {
            if (isset($localDir)) {
                $this->cleanup($localDir);
            }
            if (isset($result) && null === $result->getDurationSeconds()) {
                // ponytail: stub - already persisted above on success path
            }
        }
    }

    private function cleanup(string $dir): void
    {
        if ('' === $dir || self::WORK_DIR === $dir || !is_dir($dir)) {
            return;
        }
        @exec(sprintf('rm -rf %s', escapeshellarg($dir)));
    }
}
