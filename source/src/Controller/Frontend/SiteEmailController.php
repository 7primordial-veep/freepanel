<?php

namespace App\Controller\Frontend;

use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Mail\MailcowClient;
use App\Service\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Contracts\Translation\TranslatorInterface;

class SiteEmailController extends Controller
{
    private SiteManager $siteManager;
    private MailcowClient $mailcow;

    public function __construct(
        SiteManager $siteManager,
        MailcowClient $mailcow,
        TranslatorInterface $t,
        Logger $l
    ) {
        $this->siteManager = $siteManager;
        $this->mailcow = $mailcow;
        parent::__construct($t, $l);
    }

    public function index(Request $request): Response
    {
        $site = $this->resolveSite($request);

        $mailcowNotConfigured = false;
        $domainEnabled = false;
        $mailboxes = [];

        if (!$this->mailcow->isConfigured()) {
            $mailcowNotConfigured = true;
        } else {
            try {
                $domains = $this->mailcow->listDomains();
                $domainEnabled = $this->domainExists($domains, $site->getDomainName());
                if ($domainEnabled) {
                    $mailboxes = $this->mailcow->listMailboxesForDomain($site->getDomainName());
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $request->getSession()->getFlashBag()->set(
                    'danger',
                    $this->translator->trans(
                        'Mailcow request failed: %errorMessage%',
                        ['%errorMessage%' => $e->getMessage()]
                    )
                );
            }
        }

        return $this->render('Frontend/Site/email.html.twig', [
            'site'                 => $site,
            'mailcowNotConfigured' => $mailcowNotConfigured,
            'domainEnabled'        => $domainEnabled,
            'mailboxes'            => $mailboxes,
            'formErrors'           => $this->formErrors,
        ]);
    }

    public function enable(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('site-email-enable', (string) $request->request->get('_token'))) {
            throw new InvalidCsrfTokenException('The CSRF token is invalid.');
        }

        $site = $this->resolveSite($request);
        $session = $request->getSession();

        try {
            $this->mailcow->addDomain($site->getDomainName());
            $session->getFlashBag()->set(
                'success',
                $this->translator->trans('Domain registered in Mailcow. Add the SPF / DKIM / MX DNS records (see Mailcow admin).')
            );
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans(
                    'Failed to enable email: %errorMessage%',
                    ['%errorMessage%' => $e->getMessage()]
                )
            );
        }

        return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
    }

    public function addMailbox(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('site-email-add', (string) $request->request->get('_token'))) {
            throw new InvalidCsrfTokenException('The CSRF token is invalid.');
        }

        $site = $this->resolveSite($request);
        $session = $request->getSession();

        $localPart = trim((string) $request->request->get('local_part'));
        $password = (string) $request->request->get('password');
        $quotaRaw = (string) $request->request->get('quota');
        $quota = (int) $quotaRaw;
        if ($quota <= 0) {
            $quota = 1024;
        }

        if (!preg_match('/^[a-z0-9._-]{1,64}$/i', $localPart)) {
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans('Invalid local part. Use 1-64 characters: letters, digits, dot, underscore, or hyphen.')
            );
            return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
        }

        if (strlen($password) < 8) {
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans('Password must be at least 8 characters long.')
            );
            return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
        }

        try {
            $this->mailcow->addMailbox($localPart, $site->getDomainName(), $password, $quota, null);
            $session->getFlashBag()->set(
                'success',
                $this->translator->trans('Mailbox created.')
            );
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans(
                    'Failed to create mailbox: %errorMessage%',
                    ['%errorMessage%' => $e->getMessage()]
                )
            );
        }

        return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
    }

    public function deleteMailbox(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('site-email-delete', (string) $request->request->get('_token'))) {
            throw new InvalidCsrfTokenException('The CSRF token is invalid.');
        }

        $site = $this->resolveSite($request);
        $session = $request->getSession();

        $email = strtolower(trim((string) $request->request->get('email')));

        if (!preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/i', $email)) {
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans('Invalid email address.')
            );
            return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
        }

        $parts = explode('@', $email, 2);
        $domainPart = $parts[1] ?? '';
        if (strtolower($domainPart) !== strtolower($site->getDomainName())) {
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans('Mailbox does not belong to this site.')
            );
            return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
        }

        try {
            $this->mailcow->deleteMailbox($email);
            $session->getFlashBag()->set(
                'success',
                $this->translator->trans('Mailbox deleted.')
            );
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set(
                'danger',
                $this->translator->trans(
                    'Failed to delete mailbox: %errorMessage%',
                    ['%errorMessage%' => $e->getMessage()]
                )
            );
        }

        return $this->redirect($this->generateUrl('clp_site_email', ['domainName' => $site->getDomainName()]));
    }

    /**
     * Resolve the Site entity from the request and enforce owner-or-admin access.
     */
    private function resolveSite(Request $request)
    {
        $domainName = (string) $request->get('domainName');
        $site = $this->siteManager->findOneBy(['domainName' => $domainName]);
        if (null === $site) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (null === $user) {
            throw $this->createAccessDeniedException();
        }
        $owner = $site->getUser();
        $roles = method_exists($user, 'getRoles') ? $user->getRoles() : [];
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);
        if (!$isAdmin && (null === $owner || $user->getUserIdentifier() !== $owner)) {
            throw $this->createAccessDeniedException();
        }

        return $site;
    }

    /**
     * Mailcow's listDomains() may return a plain list of strings, or a list of associative
     * arrays keyed by 'domain_name' / 'domain' / 'name'. Normalize and look up case-insensitively.
     */
    private function domainExists(array $domains, string $needle): bool
    {
        $needle = strtolower($needle);
        foreach ($domains as $entry) {
            if (is_string($entry)) {
                if (strtolower($entry) === $needle) {
                    return true;
                }
                continue;
            }
            if (is_array($entry)) {
                foreach (['domain_name', 'domain', 'name'] as $key) {
                    if (isset($entry[$key]) && is_string($entry[$key]) && strtolower($entry[$key]) === $needle) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
