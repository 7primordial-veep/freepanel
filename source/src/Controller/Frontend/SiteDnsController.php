<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Cloudflare\Client as CloudflareClient;
use App\Entity\Manager\SiteManager;
use App\Entity\User as UserEntity;
use App\Service\Logger;

class SiteDnsController extends Controller
{
    private const ALLOWED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA'];

    private SiteManager $siteManager;
    private CloudflareClient $cloudflareClient;

    public function __construct(SiteManager $siteManager, CloudflareClient $cloudflareClient, TranslatorInterface $t, Logger $l)
    {
        $this->siteManager = $siteManager;
        $this->cloudflareClient = $cloudflareClient;
        parent::__construct($t, $l);
    }

    public function index(Request $request) : Response
    {
        $site = $this->getAuthorizedSite($request);
        if (null === $site) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }

        $cfNotConfigured = false === $this->cloudflareClient->isConfigured();
        $zone = null;
        $records = [];

        if (false === $cfNotConfigured) {
            try {
                $zone = $this->cloudflareClient->findZoneForDomain($site->getDomainName());
                if (null === $zone) {
                    $request->getSession()->getFlashBag()->set("warning", $this->translator->trans("No Cloudflare zone matches this domain."));
                } else {
                    $records = $this->cloudflareClient->listDnsRecords($zone["id"]);
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $request->getSession()->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }

        return $this->render("Frontend/Site/dns.html.twig", [
            "site" => $site,
            "zone" => $zone,
            "records" => $records,
            "cfNotConfigured" => $cfNotConfigured,
            "formErrors" => $this->formErrors,
        ]);
    }

    public function add(Request $request) : Response
    {
        $site = $this->getAuthorizedSite($request);
        if (null === $site) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $domainName = $site->getDomainName();
        $session = $request->getSession();

        if (false === $this->isCsrfTokenValid("site-dns-add", (string) $request->request->get("_token"))) {
            $session->getFlashBag()->set("danger", $this->translator->trans("Invalid CSRF token."));
            return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
        }

        try {
            if (false === $this->cloudflareClient->isConfigured()) {
                $session->getFlashBag()->set("danger", $this->translator->trans("Cloudflare is not configured."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }

            $type = strtoupper(trim((string) $request->request->get("type")));
            $name = trim((string) $request->request->get("name"));
            $content = trim((string) $request->request->get("content"));
            $ttlRaw = (int) $request->request->get("ttl");
            $ttl = $ttlRaw > 0 ? $ttlRaw : 1;
            $proxied = "1" === (string) $request->request->get("proxied");

            if (false === in_array($type, self::ALLOWED_TYPES, true)) {
                $session->getFlashBag()->set("danger", $this->translator->trans("Unsupported DNS record type."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }
            if ("" === $name || "" === $content) {
                $session->getFlashBag()->set("danger", $this->translator->trans("Name and content are required."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }

            $zone = $this->cloudflareClient->findZoneForDomain($domainName);
            if (null === $zone) {
                $session->getFlashBag()->set("warning", $this->translator->trans("No Cloudflare zone matches this domain."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }

            $this->cloudflareClient->addDnsRecord($zone["id"], $type, $name, $content, $proxied, $ttl);
            $session->getFlashBag()->set("success", $this->translator->trans("DNS record has been added."));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }

        return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
    }

    public function delete(Request $request) : Response
    {
        $site = $this->getAuthorizedSite($request);
        if (null === $site) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $domainName = $site->getDomainName();
        $session = $request->getSession();

        if (false === $this->isCsrfTokenValid("site-dns-delete", (string) $request->request->get("_token"))) {
            $session->getFlashBag()->set("danger", $this->translator->trans("Invalid CSRF token."));
            return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
        }

        try {
            if (false === $this->cloudflareClient->isConfigured()) {
                $session->getFlashBag()->set("danger", $this->translator->trans("Cloudflare is not configured."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }

            $recordId = trim((string) $request->request->get("recordId"));
            if ("" === $recordId) {
                $session->getFlashBag()->set("danger", $this->translator->trans("Missing record id."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }

            $zone = $this->cloudflareClient->findZoneForDomain($domainName);
            if (null === $zone) {
                $session->getFlashBag()->set("warning", $this->translator->trans("No Cloudflare zone matches this domain."));
                return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
            }

            $this->cloudflareClient->deleteDnsRecord($zone["id"], $recordId);
            $session->getFlashBag()->set("success", $this->translator->trans("DNS record has been deleted."));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }

        return $this->redirect($this->generateUrl("clp_site_dns", ["domainName" => $domainName]));
    }

    private function getAuthorizedSite(Request $request)
    {
        $domainName = (string) $request->get("domainName");
        if ("" === $domainName) {
            return null;
        }
        $siteEntity = $this->siteManager->findOneByDomainName($domainName);
        if (null === $siteEntity) {
            return null;
        }
        $user = $this->getUser();
        if (null === $user) {
            return null;
        }
        if (UserEntity::ROLE_USER === $user->getRole() && false === $user->hasSite($siteEntity)) {
            return null;
        }
        return $siteEntity;
    }
}
