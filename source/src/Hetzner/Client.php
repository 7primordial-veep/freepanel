<?php

namespace App\Hetzner;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\CloudPanel\Hetzner\Instance;
use App\Util\Retry;
class Client
{
    const HTTP_CLIENT_TIMEOUT = 10;
    const API_ENDPOINT = "https://api.hetzner.cloud/v1/";
    const META_DATA_ENDPOINT = "http://169.254.169.254/hetzner/v1/metadata/";
    private ?HttpClient $httpClient = null;
    private ?HttpClient $metaDataHttpClient = null;
    private ?string $token = null;
    private ?Instance $instance = null;
    private array $instanceData = [];
    private array $snapshots = [];
    public function setInstance(Instance $instance) : void
    {
        $this->instance = $instance;
    }
    public function getInstance() : ?Instance
    {
        return $this->instance;
    }
    public function setToken(string $token) : void
    {
        $this->token = $token;
    }
    public function getToken() : ?string
    {
        return $this->token;
    }
    public function getInstanceData() : array
    {
        if (true === empty($this->instanceData)) {
            $instanceId = $this->instance->getInstanceId();
            $requestUrl = sprintf("%s/servers/%s", rtrim(self::API_ENDPOINT, "/"), $instanceId);
            $request = new Request("GET", $requestUrl);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $responseData = json_decode((string) $response->getBody(), true);
                if (true === isset($responseData["server"]) && true === is_array($responseData["server"])) {
                    $this->instanceData = $responseData["server"];
                }
            }
        }
        return $this->instanceData;
    }
    public function getInstanceName() : ?string
    {
        $instanceData = $this->getInstanceData();
        $instanceName = $instanceData["name"] ?? '';
        return $instanceName;
    }
    public function getSnapshots() : array
    {
        if (true === empty($this->snapshots)) {
            $instanceId = $this->instance->getInstanceId();
            $requestUrl = sprintf("%s/images?type=snapshot", rtrim(self::API_ENDPOINT, "/"));
            $request = new Request("GET", $requestUrl);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $responseData = json_decode((string) $response->getBody(), true);
                if (true === isset($responseData["images"]) && true === is_array($responseData["images"])) {
                    foreach ($responseData["images"] as $snapshotData) {
                        if (!(true === isset($snapshotData["created_from"]["id"]) && $instanceId == $snapshotData["created_from"]["id"])) {
                            continue;
                        }
                        $id = $snapshotData["id"] ?? '';
                        $type = $snapshotData["type"] ?? '';
                        $status = $snapshotData["status"] ?? '';
                        $name = $snapshotData["description"] ?? '';
                        $size = $snapshotData["image_size"] ?? '';
                        if (Snapshot::STATUS_AVAILABLE == $status) {
                            $size = number_format($size, 1);
                        }
                        $createdAt = true === isset($snapshotData["created"]) ? new \DateTime($snapshotData["created"]) : '';
                        $isDeleteProtected = true === isset($snapshotData["protection"]["delete"]) && true === $snapshotData["protection"]["delete"] ? true : false;
                        $snapshot = new Snapshot();
                        $snapshot->setId($id);
                        $snapshot->setCreatedAt($createdAt);
                        $snapshot->setName($name);
                        $snapshot->setSize($size);
                        $snapshot->setType($type);
                        $snapshot->setStatus($status);
                        $snapshot->setIsDeleteProtected($isDeleteProtected);
                        $this->snapshots[] = $snapshot;
                    }
                    usort($this->snapshots, function ($a, $b) {
                    return $b->getCreatedAt() <=> $a->getCreatedAt();
                    });
                }
            }
        }
        return $this->snapshots;
    }
    public function getSnapshot($id) : ?Snapshot
    {
        $requestUrl = sprintf("%s/images/%s", rtrim(self::API_ENDPOINT, "/"), $id);
        $request = new Request("GET", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        $responseStatusCode = $response->getStatusCode();
        $snapshot = null;
        if (200 == $responseStatusCode) {
            $responseData = json_decode((string) $response->getBody(), true);
            if (true === isset($responseData["image"]) && true === is_array($responseData["image"])) {
                $snapshotData = $responseData["image"];
                $id = $snapshotData["id"] ?? '';
                $type = $snapshotData["type"] ?? '';
                $status = $snapshotData["status"] ?? '';
                $name = $snapshotData["description"] ?? '';
                $size = $snapshotData["image_size"] ?? '';
                if (Snapshot::STATUS_AVAILABLE == $status) {
                    $size = number_format($size, 1);
                }
                $createdAt = true === isset($snapshotData["created"]) ? new \DateTime($snapshotData["created"]) : '';
                $snapshot = new Snapshot();
                $snapshot->setId($id);
                $snapshot->setCreatedAt($createdAt);
                $snapshot->setName($name);
                $snapshot->setSize($size);
                $snapshot->setType($type);
                $snapshot->setStatus($status);
            }
        }
        return $snapshot;
    }
    public function deleteSnapshot($id) : Response
    {
        $requestUrl = sprintf("%s/images/%s", rtrim(self::API_ENDPOINT, "/"), $id);
        $request = new Request("DELETE", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        return $response;
    }
    public function createSnapshot($name) : void
    {
        $instanceId = $this->instance->getInstanceId();
        $requestUrl = sprintf("%s/servers/%s/actions/create_image", rtrim(self::API_ENDPOINT, "/"), $instanceId);
        $httpClient = $this->getHttpClient();
        $data = ["description" => $name];
        $response = $httpClient->post($requestUrl, ["body" => json_encode($data)]);
        if (201 != $response->getStatusCode()) {
            throw new \Exception(sprintf("Snapshot creation failed, status code: %s .", $response->getStatusCode()));
        }
    }
    public function getMetaDataInstanceId() : ?string
    {
        $instanceId = $this->getMetaDataValue("instance-id");
        return $instanceId;
    }
    public function getMetaDataIpv4PublicIp() : ?string
    {
        $ipv4PublicIp = $this->getMetaDataValue("public-ipv4");
        return $ipv4PublicIp;
    }
    public function getMetaDataRegion() : ?string
    {
        try {
            $region = $this->getMetaDataValue("region");
        } catch (\Exception $e) {
            $region = '';
        }
        return $region;
    }
    private function getMetaDataValue($path) : ?string
    {
        $requestUrl = sprintf("%s/%s", rtrim(self::META_DATA_ENDPOINT, "/"), $path);
        $request = new Request("GET", $requestUrl);
        $metaDataHttpClient = $this->getMetaDataHttpClient();
        $response = $this->retry(function () use($metaDataHttpClient, $request) {
            $response = $metaDataHttpClient->send($request);
            return $response;
        }, 1, 2);
        $responseStatusCode = $response->getStatusCode();
        $responseData = '';
        if (200 == $responseStatusCode) {
            $responseData = trim((string) $response->getBody());
        }
        return $responseData;
    }
    public function unsetHttpClient() : void
    {
        $this->httpClient = null;
    }
    private function getMetaDataHttpClient() : HttpClient
    {
        if (true === is_null($this->metaDataHttpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false];
            $this->metaDataHttpClient = new HttpClient($config);
        }
        return $this->metaDataHttpClient;
    }
    private function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $token = $this->getToken();
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => true, "headers" => ["Content-Type" => "application/json", "Authorization" => sprintf("Bearer %s", $token)]];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
    private function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}