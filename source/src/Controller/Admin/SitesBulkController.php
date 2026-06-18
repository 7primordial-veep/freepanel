<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\ScanResultManager;
use App\Entity\Manager\SiteManager;
use App\Entity\ScanResult;
use App\Service\Logger;
use App\System\CommandExecutor;
use App\System\Command\ClamdScanCommand;
use App\System\Command\ServiceReloadCommand;

class SitesBulkController extends Controller
{
    private const ALLOWED_ACTIONS = ['malware_scan', 'reload_nginx'];
    private const LOG_DIR = '/home/clp/htdocs/app/logs/malware-scan';

    private SiteManager $siteManager;
    private ScanResultManager $scanResultManager;
    private CommandExecutor $commandExecutor;

    public function __construct(
        SiteManager $siteManager,
        ScanResultManager $scanResultManager,
        CommandExecutor $commandExecutor,
        TranslatorInterface $translator,
        Logger $logger
    ) {
        parent::__construct($translator, $logger);
        $this->siteManager = $siteManager;
        $this->scanResultManager = $scanResultManager;
        $this->commandExecutor = $commandExecutor;
    }

    public function index(Request $request): Response
    {
        $sites = $this->siteManager->findAll([], ['domainName' => 'ASC']);

        return $this->render('Admin/Sites/bulk.html.twig', [
            'sites' => $sites,
            'active' => 'sites-bulk',
        ]);
    }

    public function apply(Request $request): Response
    {
        $this->checkCsrfToken($request, 'sites-bulk-apply');

        $session = $request->getSession();
        $flashBag = $session->getFlashBag();
        $redirectUrl = $this->generateUrl('clp_admin_sites_bulk');

        $action = (string) $request->request->get('action');
        if (false === in_array($action, self::ALLOWED_ACTIONS, true)) {
            $flashBag->set('danger', $this->translator->trans('Invalid action.'));
            return $this->redirect($redirectUrl);
        }

        $siteIdsRaw = (array) $request->request->all('siteIds');
        $siteIds = [];
        foreach ($siteIdsRaw as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $siteIds[] = $id;
            }
        }
        if (empty($siteIds)) {
            $flashBag->set('warning', $this->translator->trans('No sites selected.'));
            return $this->redirect($redirectUrl);
        }

        if ('malware_scan' === $action && !is_dir(self::LOG_DIR)) {
            @mkdir(self::LOG_DIR, 0775, true);
        }

        $results = [];
        $okCount = 0;
        $errorCount = 0;

        foreach ($siteIds as $siteId) {
            $site = $this->siteManager->findOneById($siteId);
            if (null === $site) {
                $results[] = sprintf('#%d: %s', $siteId, $this->translator->trans('site not found'));
                $errorCount++;
                continue;
            }
            $domain = (string) $site->getDomainName();

            try {
                switch ($action) {
                    case 'malware_scan':
                        $siteUser = (string) $site->getUser();
                        if ('' === $siteUser) {
                            throw new \RuntimeException('site has no system user');
                        }
                        $targetPath = sprintf('/home/%s/', $siteUser);

                        $scan = new ScanResult();
                        $scan->setSite($site);
                        $scan->setPath($targetPath);
                        $scan->setStatus(ScanResult::STATUS_RUNNING);
                        $this->scanResultManager->updateEntity($scan);

                        $logFile = sprintf('%s/scan-%d.log', self::LOG_DIR, $scan->getId());

                        $cmd = new ClamdScanCommand();
                        $cmd->setPath($targetPath);
                        $cmd->setLogFile($logFile);
                        $cmd->setRunInBackground(true);
                        $this->commandExecutor->execute($cmd);
                        break;

                    case 'reload_nginx':
                        $cmd = new ServiceReloadCommand();
                        $cmd->setServiceName('nginx');
                        $this->commandExecutor->execute($cmd);
                        break;
                }

                $results[] = sprintf('%s: ok', $domain);
                $okCount++;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $results[] = sprintf('%s: error - %s', $domain, $e->getMessage());
                $errorCount++;
            }
        }

        $summary = sprintf(
            '%s (%d ok, %d error): %s',
            $action,
            $okCount,
            $errorCount,
            implode('; ', $results)
        );

        if (0 === $errorCount) {
            $flashBag->set('success', $summary);
        } else {
            $flashBag->set('warning', $summary);
        }

        return $this->redirect($redirectUrl);
    }
}
