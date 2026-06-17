<?php

namespace App\Site\Ssl\Dns;

interface DnsProviderInterface
{
    /**
     * Identifier matched against the dns_provider config value (e.g. cloudflare).
     */
    public function getName(): string;

    /**
     * Create a TXT record for $name (FQDN, e.g. _acme-challenge.example.com)
     * with the given value. Returns an opaque record id used for later removal.
     */
    public function createTxtRecord(string $name, string $value, int $ttl = 60): string;

    /**
     * Remove a TXT record previously created by createTxtRecord().
     */
    public function deleteTxtRecord(string $name, string $recordId): void;
}
