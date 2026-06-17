<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Backup\Rclone;
use App\Backup\Restore\BackupRestorer;
use App\Entity\Manager\SiteManager;
use App\Entity\Manager\ConfigManager;
use App\Event\EventQueue;
use App\Service\Logger;

class RemoteBackupRestoreController extends Controller
{
    private Rclone $rclone;
    private BackupRestorer $restorer;
    private SiteManager $siteManager;
    private ConfigManager $config;

    public function __construct(
        Rclone $rclone,
        BackupRestorer $restorer,
        SiteManager $siteManager,
        ConfigManager $config,
        TranslatorInterface $translator,
        Logger $logger
    ) {
        $this->rclone = $rclone;
        $this->restorer = $restorer;
        $this->siteManager = $siteManager;
        $this->config = $config;
        parent::__construct($translator, $logger);
    }

    public function index(Request $request): Response
    {
        $storageDirectory = (string) $this->config->get('remote_backup_storage_directory');
        $provider = (string) $this->config->get('remote_backup_storage_provider');

        if ('' === $storageDirectory || '' === $provider) {
            return $this->render('Admin/RemoteBackup/restore.html.twig', [
                'error' => 'Remote backup is not configured.',
                'entries' => [],
                'date' => null,
                'time' => null,
                'storageDirectory' => $storageDirectory,
                'provider' => $provider,
                'sites' => $this->siteManager->findAll(),
                'active' => 'remote-backups',
            ]);
        }

        $date = $request->query->get('date');
        $time = $request->query->get('time');
        if (is_string($date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = null;
        }
        if (is_string($time) && !preg_match('/^[0-9]{2}\.[0-9]{2}$/', $time)) {
            $time = null;
        }

        $entries = [];
        $level = 'date';
        $error = null;

        try {
            $base = rtrim($storageDirectory, '/');
            if (null === $date) {
                $entries = $this->rclone->lsJson('remote:' . $base, true);
                $level = 'date';
            } elseif (null === $time) {
                $entries = $this->rclone->lsJson('remote:' . $base . '/' . $date, true);
                $level = 'time';
            } else {
                $entries = $this->rclone->lsJson('remote:' . $base . '/' . $date . '/' . $time . '/home', true);
                $level = 'site';
            }
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $error = 'Failed to list remote backups: ' . $e->getMessage();
        }

        // Build object paths for site entries so the form can submit a definitive backup_object.
        $siteObjects = [];
        if ('site' === $level) {
            foreach ($entries as $entry) {
                $name = is_array($entry) ? ($entry['Name'] ?? $entry['Path'] ?? null) : null;
                if (!is_string($name) || '' === $name) {
                    continue;
                }
                $base = rtrim($storageDirectory, '/');
                $siteObjects[] = [
                    'name' => $name,
                    'object_gz' => $base . '/' . $date . '/' . $time . '/home/' . $name . '/backup.tar.gz',
                    'object_tar' => $base . '/' . $date . '/' . $time . '/home/' . $name . '/backup.tar',
                ];
            }
        }

        return $this->render('Admin/RemoteBackup/restore.html.twig', [
            'error' => $error,
            'entries' => $entries,
            'level' => $level,
            'date' => $date,
            'time' => $time,
            'storageDirectory' => $storageDirectory,
            'provider' => $provider,
            'siteObjects' => $siteObjects,
            'sites' => $this->siteManager->findAll(),
            'active' => 'remote-backups',
        ]);
    }

    public function apply(Request $request): Response
    {
        $this->checkCsrfToken($request, 'remote-backup-restore');
        $session = $request->getSession();

        $backupObject = trim((string) $request->request->get('backup_object', ''));
        $targetDomain = trim((string) $request->request->get('target_domain', ''));

        if ('' === $backupObject || '' === $targetDomain) {
            $session->getFlashBag()->set('danger', $this->translator->trans('backup_object and target_domain are required.'));
            return $this->redirect($this->generateUrl('clp_admin_remote_backup_restore'));
        }

        try {
            $result = $this->restorer->restore($backupObject, $targetDomain);
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $result = ['ok' => false, 'output' => $e->getMessage()];
        }

        if (true === ($result['ok'] ?? false)) {
            $session->getFlashBag()->set('success', $this->translator->trans('Restore completed: %output%', ['%output%' => (string) $result['output']]));
        } else {
            $session->getFlashBag()->set('danger', $this->translator->trans('Restore failed: %output%', ['%output%' => (string) ($result['output'] ?? 'unknown error')]));
        }

        $user = $this->getUser();
        if (null !== $user) {
            try {
                EventQueue::addEvent(
                    EventQueue::EVENT_SITE_BACKUP_RESTORE,
                    $user,
                    [
                        'target_domain' => $targetDomain,
                        'backup_object' => $backupObject,
                        'ok' => (bool) ($result['ok'] ?? false),
                    ],
                    $request
                );
            } catch (\Throwable $e) {
                $this->logger->exception($e);
            }
        }

        return $this->redirect($this->generateUrl('clp_admin_remote_backup_restore'));
    }
}
