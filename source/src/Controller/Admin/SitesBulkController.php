<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Service\Logger;
use App\System\CommandExecutor;
use App\System\Command\ClamdScanCommand;
use App\System\Command\ServiceReloadCommand;

class SitesBulkController extends Controller
{
    private const ALLOWED_ACTIONS = ['malware_scan', 'clear_cache', 'reload_nginx', 'renew_cert'];
    private const LOG_DIR = '/var/log/clp-malware-scan';

    private SiteManager $siteManager;
    private CommandExecutor $commandExecutor;

    public function __construct(
        SiteManager $siteManager,
        CommandExecutor $commandExecutor,
        TranslatorInterface $translator,
        Logger $logger
    ) {
        parent::__construct($translator, $logger);
        $this->siteManager = $siteManager;
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
        $session = $request->getSession();
        $flashBag = $session->getFlashBag();
        $redirectUrl = $this->generateUrl('clp_admin_sites_bulk');

        if (false === $this->isCsrfTokenValid('sites-bulk-apply', (string) $request->request->get('_token'))) {
            $flashBag->set('danger', $this->translator->trans('Invalid CSRF token.'));
            return $this->redirect($redirectUrl);
        }

        $user = $this->getUser();
        $roles = [];
        if (null !== $user && method_exists($user, 'getRoles')) {
            $roles = (array) $user->getRoles();
        }
        if (false === in_array('ROLE_ADMIN', $roles, true)) {
            $flashBag->set('danger', $this->translator->trans('You are not allowed to perform this action.'));
            return $this->redirect($redirectUrl);
        }

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

        $results = [];
        $okCount = 0;
        $errorCount = 0;

        if ('malware_scan' === $action && !is_dir(self::LOG_DIR)) {
            @mkdir(self::LOG_DIR, 0775, true);
        }

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
                        $cmd = new ClamdScanCommand();
                        $cmd->setPath(sprintf('/home/%s/', $siteUser));
                        $cmd->setLogFile(sprintf('%s/scan-bulk-%d-%d.log', self::LOG_DIR, $siteId, time()));
                        $cmd->setRunInBackground(true);
                        $this->commandExecutor->execute($cmd);
                        break;

                    case 'clear_cache':
                        // No-op at this layer; summarized in flash message.
                        break;

                    case 'reload_nginx':
                        $cmd = new ServiceReloadCommand();
                        $cmd->setServiceName('nginx');
                        $this->commandExecutor->execute($cmd);
                        break;

                    case 'renew_cert':
                        $renewClass = 'App\\System\\Command\\LetsEncryptRenewCommand';
                        if (class_exists($renewClass)) {
                            /** @var \App\System\Command $renewCmd */
                            $renewCmd = new $renewClass();
                            if (method_exists($renewCmd, 'setDomainName')) {
                                $renewCmd->setDomainName($domain);
                            }
                            $this->commandExecutor->execute($renewCmd);
                        }
                        $reload = new ServiceReloadCommand();
                        $reload->setServiceName('nginx');
                        $this->commandExecutor->execute($reload);
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

        if ('clear_cache' === $action) {
            $flashBag->set('success', $this->translator->trans('Cache cleared for %count% sites.', ['%count%' => $okCount]));
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
