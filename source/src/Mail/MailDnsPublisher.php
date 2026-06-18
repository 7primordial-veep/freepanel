<?php

declare(strict_types=1);

namespace App\Mail;

use App\Cloudflare\Client as CloudflareClient;
use App\Entity\Manager\ConfigManager;
use Psr\Log\LoggerInterface;

class MailDnsPublisher
{
    public function __construct(
        private MailcowClient $mailcow,
        private CloudflareClient $cloudflare,
        private ConfigManager $config,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Publish standard mail records (MX/SPF/DKIM/DMARC) for $domain into the
     * Cloudflare zone that owns the domain. Returns:
     *   ['ok' => bool, 'created' => string[], 'skipped' => string[], 'errors' => string[], 'reason' => ?string]
     *
     * 'ok' is true if we published at least one record OR everything was already in place.
     * 'reason' is set when we bailed early (no cloudflare token, no zone, no mailcow hostname configured).
     */
    public function publishForDomain(string $domain): array
    {
        $result = ['ok' => false, 'created' => [], 'skipped' => [], 'errors' => [], 'reason' => null];

        if (!$this->cloudflare->isConfigured()) {
            $result['reason'] = 'Cloudflare API token not configured.';
            return $result;
        }

        $mailHost = trim((string) $this->config->get('mailcow_hostname'));
        if ('' === $mailHost) {
            // Derive from mailcow_api_url if explicit hostname not set.
            $apiUrl = (string) $this->config->get('mailcow_api_url');
            $parsed = parse_url($apiUrl);
            $mailHost = is_array($parsed) ? (string) ($parsed['host'] ?? '') : '';
        }
        if ('' === $mailHost) {
            $result['reason'] = 'Mailcow hostname not configured.';
            return $result;
        }

        try {
            $zone = $this->cloudflare->findZoneForDomain($domain);
        } catch (\Throwable $e) {
            $result['reason'] = 'Cloudflare lookup failed: ' . $e->getMessage();
            return $result;
        }
        if (null === $zone) {
            $result['reason'] = 'No Cloudflare zone matches ' . $domain;
            return $result;
        }
        $zoneId = (string) $zone['id'];

        // Ensure DKIM key exists in Mailcow, then fetch it.
        $dkim = null;
        try {
            $dkim = $this->mailcow->getDkim($domain);
            $pub = is_array($dkim) ? ($dkim['dkim_pubkey'] ?? $dkim['pubkey'] ?? null) : null;
            if (null === $dkim || empty($pub)) {
                $this->mailcow->addDkim($domain);
                $dkim = $this->mailcow->getDkim($domain);
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'DKIM fetch/create failed: ' . $e->getMessage();
            $dkim = null;
        }
        $dkimPub = is_array($dkim) ? (string) ($dkim['dkim_pubkey'] ?? $dkim['pubkey'] ?? '') : '';

        // Fetch existing records once to dedupe.
        try {
            $existing = $this->cloudflare->listDnsRecords($zoneId);
        } catch (\Throwable $e) {
            $existing = [];
            $result['errors'][] = 'list dns records failed: ' . $e->getMessage();
        }

        $records = [];
        $records[] = ['type' => 'MX',  'name' => $domain,                          'content' => $mailHost,                                                              'proxied' => false, 'priority' => 10];
        $records[] = ['type' => 'TXT', 'name' => $domain,                          'content' => 'v=spf1 mx -all',                                                       'proxied' => false];
        if ('' !== $dkimPub) {
            if (1 === preg_match('/^[A-Za-z0-9+\/=]+$/', $dkimPub)) {
                $records[] = ['type' => 'TXT', 'name' => 'dkim._domainkey.' . $domain, 'content' => 'v=DKIM1; k=rsa; p=' . $dkimPub,                                       'proxied' => false];
            } else {
                $result['errors'][] = 'DKIM pubkey contains invalid characters; skipping DKIM TXT record.';
                if (null !== $this->logger) {
                    $this->logger->warning('MailDnsPublisher rejected DKIM pubkey', [
                        'domain' => $domain,
                    ]);
                }
            }
        }
        $records[] = ['type' => 'TXT', 'name' => '_dmarc.' . $domain,              'content' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain,             'proxied' => false];

        foreach ($records as $rec) {
            // dedupe: an existing record with same (type, name) counts as "skipped"
            $dup = null;
            foreach ($existing as $e) {
                if (strcasecmp((string) ($e['type'] ?? ''), $rec['type']) === 0
                    && strcasecmp((string) ($e['name'] ?? ''), $rec['name']) === 0) {
                    $dup = $e;
                    break;
                }
            }
            if (null !== $dup) {
                $result['skipped'][] = sprintf('%s %s', $rec['type'], $rec['name']);
                continue;
            }
            try {
                $this->cloudflare->addDnsRecord($zoneId, $rec['type'], $rec['name'], $rec['content'], $rec['proxied'], 1, $rec['priority'] ?? null);
                $result['created'][] = sprintf('%s %s', $rec['type'], $rec['name']);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('%s %s failed: %s', $rec['type'], $rec['name'], $e->getMessage());
                if (null !== $this->logger) {
                    $this->logger->warning('MailDnsPublisher addDnsRecord failed', [
                        'domain' => $domain,
                        'type' => $rec['type'],
                        'name' => $rec['name'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $result['ok'] = empty($result['errors']) || !empty($result['created']) || !empty($result['skipped']);
        return $result;
    }
}
