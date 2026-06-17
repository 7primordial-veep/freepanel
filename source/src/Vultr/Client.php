<?php

namespace App\Vultr;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\CloudPanel\Vultr\Instance;
use App\Vultr\Instance as VultrInstance;
use App\Util\HumanFileSize as HumanFileSizeUtil;
use App\Util\Retry;
class Client
{
    const HTTP_CLIENT_TIMEOUT = 30;
    const API_ENDPOINT = "https://api.vultr.com/v2/";
    const META_DATA_ENDPOINT = "http://169.254.169.254/";
    private ?HttpClient $httpClient = null;
    private ?HttpClient $metaDataHttpClient = null;
    private ?string $apiKey = null;
    private ?Instance $vultrInstance = null;
    private ?VultrInstance $instance = null;
    private array $instanceData = [];
    private array $snapshots = [];
    public function setVultrInstance(Instance $vultrInstance) : void
    {
        $this->vultrInstance = $vultrInstance;
    }
    public function getVultrInstance() : ?Instance
    {
        return $this->vultrInstance;
    }
    public function setApiKey(string $apiKey) : void
    {
        $this->apiKey = $apiKey;
    }
    public function getApiKey() : ?string
    {
        return $this->apiKey;
    }
    public function createSnapshot(string $description) : void
    {
        $instance = $this->getInstance();
        $instanceId = $instance->getId();
        $requestUrl = sprintf("%s/snapshots", rtrim(self::API_ENDPOINT, "/"));
        $httpClient = $this->getHttpClient();
        $data = ["instance_id" => $instanceId, "description" => $description];
        $response = $httpClient->post($requestUrl, ["body" => json_encode($data)]);
        if (201 != $response->getStatusCode()) {
            throw new \Exception(sprintf("Snapshot creation failed, status code: %s .", $response->getStatusCode()));
        }
    }
    public function getInstanceId() : ?string
    {
        $instance = $this->getInstance();
        $instanceId = $instance->getId();
        return $instanceId;
    }
    public function getInstance() : ?VultrInstance
    {
        $instanceData = $this->getInstanceData();
        if (false === empty($instanceData)) {
            $id = $instanceData["id"] ?? '';
            $os = $instanceData["os"] ?? '';
            $ram = $instanceData["ram"] ?? '';
            $disk = $instanceData["disk"] ?? '';
            $mainIp = $instanceData["main_ip"] ?? '';
            $region = $instanceData["region"] ?? '';
            $status = $instanceData["status"] ?? '';
            $label = $instanceData["label"] ?? '';
            $internalIp = $instanceData["internal_ip"] ?? '';
            $this->instance = new VultrInstance();
            $this->instance->setId($id);
            $this->instance->setOs($os);
            $this->instance->setRam($ram);
            $this->instance->setDisk($disk);
            $this->instance->setMainIp($mainIp);
            $this->instance->setRegion($region);
            $this->instance->setStatus($status);
            $this->instance->setLabel($label);
            $this->instance->setInternalIp($internalIp);
        }
        return $this->instance;
    }
    public function getInstanceData() : array
    {
        if (true === empty($this->instanceData)) {
            $ipv4PublicIp = $this->vultrInstance->getIpv4PublicIp();
            $requestUrl = sprintf("%s/instances?main_ip=%s", rtrim(self::API_ENDPOINT, "/"), $ipv4PublicIp);
            $request = new Request("GET", $requestUrl);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $responseData = json_decode((string) $response->getBody(), true);
                if (true === isset($responseData["instances"][0]) && true === is_array($responseData["instances"][0])) {
                    $this->instanceData = $responseData["instances"][0];
                }
            }
        }
        return $this->instanceData;
    }
    public function getSnapshots() : array
    {
        if (true === empty($this->snapshots)) {
            $requestUrl = sprintf("%s/snapshots?per_page=500", rtrim(self::API_ENDPOINT, "/"));
            $request = new Request("GET", $requestUrl);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $responseData = json_decode((string) $response->getBody(), true);
                if (true === isset($responseData["snapshots"]) && true === is_array($responseData["snapshots"])) {
                    $instanceId = $this->getInstanceId();
                    foreach ($responseData["snapshots"] as $snapshotData) {
                        $id = $snapshotData["id"] ?? '';
                        $createdAt = true === isset($snapshotData["date_created"]) ? new \DateTime($snapshotData["date_created"]) : '';
                        $description = $snapshotData["description"] ?? '';
                        $size = $snapshotData["size"] ?? 0;
                        $compressedSize = $snapshotData["compressed_size"] ?? 0;
                        $status = $snapshotData["status"] ?? '';
                        if (!(true == str_contains($description, $instanceId))) {
                            continue;
                        }
                        $snapshot = new Snapshot();
                        $snapshot->setId($id);
                        $snapshot->setCreatedAt($createdAt);
                        $snapshot->setDescription($description);
                        if (Snapshot::STATUS_COMPLETE == $status) {
                            $compressedSize = HumanFileSizeUtil::convert($compressedSize, "GB", 0);
                        }
                        $snapshot->setSize($size);
                        $snapshot->setCompressedSize($compressedSize);
                        $snapshot->setStatus($status);
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
        $requestUrl = sprintf("%s/snapshots/%s", rtrim(self::API_ENDPOINT, "/"), $id);
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
            if (true === isset($responseData["snapshot"]) && true === is_array($responseData["snapshot"])) {
                $snapshotData = $responseData["snapshot"];
                $id = $snapshotData["id"] ?? '';
                $createdAt = true === isset($snapshotData["date_created"]) ? new \DateTime($snapshotData["date_created"]) : '';
                $description = $snapshotData["description"] ?? '';
                $size = $snapshotData["size"] ?? 0;
                $compressedSize = $snapshotData["compressed_size"] ?? 0;
                $status = $snapshotData["status"] ?? '';
                $snapshot = new Snapshot();
                $snapshot->setId($id);
                $snapshot->setCreatedAt($createdAt);
                $snapshot->setDescription($description);
                if (Snapshot::STATUS_COMPLETE == $status) {
                    $compressedSize = HumanFileSizeUtil::convert($compressedSize, "GB", 0);
                }
                $snapshot->setSize($size);
                $snapshot->setCompressedSize($compressedSize);
                $snapshot->setStatus($status);
            }
        }
        return $snapshot;
    }
    public function deleteSnapshot($id) : Response
    {
        $requestUrl = sprintf("%s/snapshots/%s", rtrim(self::API_ENDPOINT, "/"), $id);
        $request = new Request("DELETE", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        return $response;
    }
    public function getMetaDataInstanceId() : ?string
    {
        $instanceId = $this->getMetaDataValue("latest/meta-data/instance-v2-id");
        return $instanceId;
    }
    public function getMetaDataIpv4PublicIp() : ?string
    {
        $ipv4PublicIp = $this->getMetaDataValue("latest/meta-data/public-ipv4");
        return $ipv4PublicIp;
    }
    public function getMetaDataRegion() : ?string
    {
        $region = $this->getMetaDataValue("v1/region/regioncode");
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
        });
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
            $apiKey = $this->getApiKey();
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => true, "headers" => ["Content-Type" => "application/json", "Authorization" => sprintf("Bearer %s", $apiKey)]];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
    private function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}