<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Manager\ConfigManager;
use App\Service\Crypto;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class MailcowClient
{
    private GuzzleClient $http;

    public function __construct(
        private ConfigManager $configManager,
        ?GuzzleClient $http = null
    ) {
        $this->http = $http ?? new GuzzleClient(['timeout' => 10]);
    }

    public function isConfigured(): bool
    {
        $url = (string) $this->configManager->get('mailcow_api_url');
        $key = (string) $this->configManager->get('mailcow_api_key');

        return $url !== '' && $key !== '';
    }

    public function addDomain(string $domain, array $opts = []): array
    {
        $payload = array_merge([
            'domain' => $domain,
            'active' => 1,
        ], $opts);

        return $this->request('POST', '/api/v1/add/domain', $payload);
    }

    public function listDomains(): array
    {
        $result = $this->request('GET', '/api/v1/get/domain/all');

        return is_array($result) ? $result : [];
    }

    public function deleteDomain(string $domain): void
    {
        $this->request('POST', '/api/v1/delete/domain', [$domain]);
    }

    public function addMailbox(
        string $local_part,
        string $domain,
        string $password,
        int $quota = 1024,
        ?string $name = null
    ): array {
        $payload = [
            'local_part' => $local_part,
            'domain' => $domain,
            'password' => $password,
            'password2' => $password,
            'quota' => $quota,
            'name' => $name ?? $local_part,
            'active' => 1,
        ];

        return $this->request('POST', '/api/v1/add/mailbox', $payload);
    }

    public function listMailboxesForDomain(string $domain): array
    {
        $result = $this->request('GET', '/api/v1/get/mailbox/all/' . rawurlencode($domain));

        return is_array($result) ? $result : [];
    }

    public function deleteMailbox(string $email): void
    {
        $this->request('POST', '/api/v1/delete/mailbox', [$email]);
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Mailcow API is not configured.');
        }

        $baseUrl = rtrim((string) $this->configManager->get('mailcow_api_url'), '/');
        $encryptedKey = (string) $this->configManager->get('mailcow_api_key');

        try {
            $apiKey = Crypto::decrypt($encryptedKey);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to decrypt Mailcow API key: ' . $e->getMessage());
        }

        $options = [
            'headers' => [
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'verify' => false,
            'http_errors' => false,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $baseUrl . $path, $options);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Mailcow API request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new RuntimeException(sprintf('Mailcow API returned HTTP %d: %s', $status, $body));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
