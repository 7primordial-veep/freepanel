<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Entity\Manager\ConfigManager;
use App\Mail\MailcowClient;
use App\Mail\MailcowInstaller;
use App\Service\Crypto;
use App\Service\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class MailcowController extends Controller
{
    public function __construct(
        private MailcowInstaller $installer,
        private MailcowClient $client,
        private ConfigManager $configManager,
        TranslatorInterface $translator,
        Logger $logger
    ) {
        parent::__construct($translator, $logger);
    }

    public function index(Request $request): Response
    {
        $installed = $this->installer->isInstalled();
        $running = $installed ? $this->installer->isRunning() : false;
        $clientConfigured = $this->client->isConfigured();
        $mailHostname = '';

        if ($installed) {
            $confPath = '/opt/mailcow-dockerized/mailcow.conf';
            if (is_readable($confPath)) {
                $contents = (string) @file_get_contents($confPath);
                if (preg_match('/^MAILCOW_HOSTNAME=(.+)$/m', $contents, $matches)) {
                    $mailHostname = trim($matches[1]);
                }
            }
        }

        return $this->render('Admin/Mailcow/index.html.twig', [
            'installed' => $installed,
            'running' => $running,
            'clientConfigured' => $clientConfigured,
            'mailHostname' => $mailHostname,
            'active' => 'mailcow',
        ]);
    }

    public function install(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mailcow-install', (string) $request->request->get('_token'))) {
            $request->getSession()->getFlashBag()->set('danger', $this->translator->trans('Invalid CSRF token.'));

            return $this->redirect($this->generateUrl('clp_admin_mailcow'));
        }

        $user = $this->getUser();
        if ($user === null || !in_array('ROLE_ADMIN', (array) $user->getRoles(), true)) {
            $request->getSession()->getFlashBag()->set('danger', $this->translator->trans('Access denied.'));

            return $this->redirect($this->generateUrl('clp_admin_mailcow'));
        }

        $mailHostname = trim((string) $request->request->get('mailHostname', ''));

        if ($this->installer->isInstalled()) {
            $request->getSession()->getFlashBag()->set('warning', $this->translator->trans('Mailcow already installed'));

            return $this->redirect($this->generateUrl('clp_admin_mailcow'));
        }

        $result = $this->installer->install($mailHostname);

        if (!empty($result['ok'])) {
            $request->getSession()->getFlashBag()->set('success', $this->translator->trans('Mailcow has been installed successfully.'));
        } else {
            $message = $this->translator->trans('Failed to install Mailcow.');
            if (!empty($result['output'])) {
                $message .= "\n" . $result['output'];
            }
            $request->getSession()->getFlashBag()->set('danger', $message);
        }

        return $this->redirect($this->generateUrl('clp_admin_mailcow'));
    }

    public function settings(Request $request): Response
    {
        return $this->render('Admin/Mailcow/settings.html.twig', [
            'apiUrl' => (string) $this->configManager->get('mailcow_api_url'),
            'hasKey' => !empty($this->configManager->get('mailcow_api_key')),
            'active' => 'mailcow',
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mailcow-save-settings', (string) $request->request->get('_token'))) {
            $request->getSession()->getFlashBag()->set('danger', $this->translator->trans('Invalid CSRF token.'));

            return $this->redirect($this->generateUrl('clp_admin_mailcow_settings'));
        }

        $apiUrl = trim((string) $request->request->get('apiUrl', ''));
        $apiKey = (string) $request->request->get('apiKey', '');

        if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl)) {
            $request->getSession()->getFlashBag()->set('danger', $this->translator->trans('API URL must start with http:// or https://'));

            return $this->redirect($this->generateUrl('clp_admin_mailcow_settings'));
        }

        $this->configManager->set('mailcow_api_url', rtrim($apiUrl, '/'));

        if ($apiKey !== '') {
            $this->configManager->set('mailcow_api_key', Crypto::encrypt($apiKey));
        }

        $request->getSession()->getFlashBag()->set('success', $this->translator->trans('Mailcow settings saved.'));

        return $this->redirect($this->generateUrl('clp_admin_mailcow_settings'));
    }
}
