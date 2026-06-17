<?php

namespace App\Cdn;

use App\Entity\Manager\ConfigManager;
use App\Service\Crypto;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;

class BunnyClient
{
    private const API_BASE = 'https://api.bunny.net';

    private ConfigManager $config;
    private ?HttpClient $http;

    public function __construct(ConfigManager $config, ?HttpClient $http = null)
    {
        $this->config = $config;
        $this->http = $http;
    }

    public function isConfigured(): bool
    {
        return null !== $this->getApiKey();
    }

    public function getApiKey(): ?string
    {
        $enc = (string) $this->config->get('bunny_api_key');
        if ('' === $enc) {
            return null;
        }
        try {
            $decrypted = Crypto::decrypt($enc);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($decrypted) || '' === $decrypted) {
            return null;
        }
        return $decrypted;
    }

    /**
     * Create a pull zone for a domain that already serves on https://<domain>.
     * Returns the API result array (includes 'Hostnames', 'Id', etc.)
     */
    public function createPullZone(string $name, string $originUrl): array
    {
        return $this->request('POST', '/pullzone', [
            'Name' => $name,
            'OriginUrl' => $originUrl,
        ]);
    }

    public function listPullZones(): array
    {
        $res = $this->request('GET', '/pullzone');
        return is_array($res) ? $res : [];
    }

    public function deletePullZone(int $pullZoneId): void
    {
        $this->request('DELETE', '/pullzone/' . $pullZoneId);
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        $apiKey = $this->getApiKey();
        if (null === $apiKey) {
            throw new \RuntimeException('Bunny API key not configured.');
        }

        $http = $this->http ?? new HttpClient(['timeout' => 15, 'http_errors' => false]);
        $opts = [
            'headers' => [
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];
        if (null !== $json) {
            $opts['json'] = $json;
        }

        try {
            $resp = $http->request($method, self::API_BASE . $path, $opts);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Bunny API request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $resp->getStatusCode();
        $body = (string) $resp->getBody();

        if ($status >= 400) {
            throw new \RuntimeException('Bunny API error ' . $status . ': ' . $body);
        }

        if ('' === $body) {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
