<?php

namespace App\Do;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\CloudPanel\Do\Instance;
use App\Util\Retry;
class Client
{
    private const HTTP_CLIENT_TIMEOUT = 10;
    private const API_ENDPOINT = "https://api.digitalocean.com/v2/";
    private const META_DATA_ENDPOINT = "http://169.254.169.254/metadata/v1/";
    private array $dropletData = [];
    private ?HttpClient $httpClient = null;
    private ?string $token = null;
    private Instance $instance;
    public function setInstance(Instance $instance) : void
    {
        $this->instance = $instance;
    }
    public function getInstance() : Instance
    {
        return $this->instance;
    }
    public function getDroplet() : ?Droplet
    {
        $dropletId = $this->instance->getDropletId();
        $dropletData = $this->getDropletData();
        $droplet = null;
        if (false === empty($dropletData)) {
            $dropletName = $dropletData["name"] ?? '';
            $dropletStatus = $dropletData["status"] ?? '';
            $dropletVCpus = $dropletData["vcpus"] ?? 0;
            $dropletMemory = $dropletData["memory"] ?? 0;
            $dropletRegionName = $dropletData["region"]["name"] ?? '';
            $dropletRegionSlug = $dropletData["region"]["slug"] ?? '';
            $dropletTags = $dropletData["tags"] ?? [];
            $dropletVolumeIds = $dropletData["volume_ids"] ?? [];
            $dropletPublicIpAddress = $dropletData["networks"]["v4"][0]["ip_address"] ?? '';
            $droplet = new Droplet();
            $droplet->setId($dropletId);
            $droplet->setName($dropletName);
            $droplet->setStatus($dropletStatus);
            $droplet->setVCPUs($dropletVCpus);
            $droplet->setMemory($dropletMemory);
            $droplet->setRegionName($dropletRegionName);
            $droplet->setRegionSlug($dropletRegionSlug);
            $droplet->setPublicIpAddress($dropletPublicIpAddress);
            $droplet->setTags($dropletTags);
            $droplet->setVolumeIds($dropletVolumeIds);
        }
        return $droplet;
    }
    private function getDropletData() : array
    {
        $dropletId = $this->instance->getDropletId();
        if (true === empty($this->dropletData)) {
            $requestUrl = sprintf("%s/droplets/%s", rtrim(self::API_ENDPOINT, "/"), $dropletId);
            $request = new Request("GET", $requestUrl);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $responseData = json_decode((string) $response->getBody(), true);
                if (true === isset($responseData["droplet"]) && true === is_array($responseData["droplet"])) {
                    $this->dropletData = $responseData["droplet"];
                }
            }
        }
        return $this->dropletData;
    }
    public function getVolume($id) : ?Volume
    {
        $volume = null;
        $requestUrl = sprintf("%s/volumes/%s", rtrim(self::API_ENDPOINT, "/"), $id);
        $request = new Request("GET", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        $responseStatusCode = $response->getStatusCode();
        if (200 == $responseStatusCode) {
            $responseData = trim((string) $response->getBody());
            $responseData = json_decode($responseData, true);
            if (true === is_array($responseData)) {
                $doVolume = true === isset($responseData["volume"]) ? (array) $responseData["volume"] : [];
                $id = $doVolume["id"] ?? '';
                $name = $doVolume["name"] ?? '';
                $description = $doVolume["description"] ?? '';
                $createdAt = true === isset($doVolume["created_at"]) ? new \DateTime($doVolume["created_at"]) : new \DateTime();
                $size = true === isset($doVolume["size_gigabytes"]) ? (float) $doVolume["size_gigabytes"] : 0.0;
                $tags = true === isset($doVolume["tags"]) ? (array) $doVolume["tags"] : [];
                $volume = new Volume();
                $volume->setId($id);
                $volume->setName($name);
                $volume->setDescription($description);
                $volume->setCreatedAt($createdAt);
                $volume->setSize($size);
                $volume->setTags($tags);
            }
        }
        return $volume;
    }
    public function getDropletSnapshots() : array
    {
        $snapshots = [];
        $requestUrl = sprintf("%s/snapshots?page=1&per_page=10000&resource_type=droplet", rtrim(self::API_ENDPOINT, "/"));
        $request = new Request("GET", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        $responseStatusCode = $response->getStatusCode();
        if (200 == $responseStatusCode) {
            $responseData = trim((string) $response->getBody());
            $responseData = json_decode($responseData, true);
            if (true === is_array($responseData)) {
                $doSnapshots = $responseData["snapshots"] ?? [];
                foreach ($doSnapshots as $snapshot) {
                    $id = $snapshot["id"] ?? '';
                    $resourceId = $snapshot["resource_id"] ?? '';
                    $name = $snapshot["name"] ?? '';
                    if (!(false === empty($name) && "-clp" == substr($name, -4))) {
                        continue;
                    }
                    $createdAt = true === isset($snapshot["created_at"]) ? new \DateTime($snapshot["created_at"]) : new \DateTime();
                    $tags = true === isset($snapshot["tags"]) ? (array) $snapshot["tags"] : [];
                    $size = true === isset($snapshot["size_gigabytes"]) ? (float) $snapshot["size_gigabytes"] : 0.0;
                    $snapshot = new DropletSnapshot();
                    $snapshot->setId($id);
                    $snapshot->setResourceId($resourceId);
                    $snapshot->setName($name);
                    $snapshot->setCreatedAt($createdAt);
                    $snapshot->setSize($size);
                    $snapshot->setTags($tags);
                    $snapshots[] = $snapshot;
                }
            }
        }
        return $snapshots;
    }
    public function getSnapshotsForDroplet() : array
    {
        $dropletId = $this->instance->getDropletId();
        $snapshotsForDroplet = [];
        $snapshots = $this->getDropletSnapshots();
        foreach ($snapshots as $snapshot) {
            $snapshotDropletId = $snapshot->getResourceId();
            if (!($snapshotDropletId == $dropletId)) {
                continue;
            }
            $snapshotsForDroplet[] = $snapshot;
        }
        return $snapshotsForDroplet;
    }
    public function getDropletSnapshot($id) : ?DropletSnapshot
    {
        $dropletSnapshot = null;
        $snapshots = $this->getDropletSnapshots();
        foreach ($snapshots as $snapshot) {
            $snapshotId = $snapshot->getId();
            if (!($snapshotId == $id)) {
                continue;
            }
            $dropletSnapshot = $snapshot;
            break;
        }
        return $dropletSnapshot;
    }
    public function deleteDropletSnapshot($id) : Response
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
    public function createDropletSnapshot($snapshotName) : Response
    {
        if ("-clp" != substr($snapshotName, -4)) {
            $snapshotName = sprintf("%s-clp", $snapshotName);
        }
        $dropletId = $this->instance->getDropletId();
        $requestUrl = sprintf("%s/droplets/%s/actions", rtrim(self::API_ENDPOINT, "/"), $dropletId);
        $httpClient = $this->getHttpClient();
        $data = ["name" => $snapshotName, "type" => "snapshot"];
        $response = $this->retry(function () use($httpClient, $requestUrl, $data) {
            $response = $httpClient->post($requestUrl, ["body" => json_encode($data)]);
            return $response;
        });
        return $response;
    }
    public function createVolumeSnapshot($id) : void
    {
        $volume = $this->getVolume($id);
        if (false === is_null($volume)) {
            $dateTime = new \DateTime();
            $volumeName = $volume->getName();
            $volumeSnapshotName = sprintf("%s-%s-%s-clp", $volumeName, $dateTime->format("Y-m-d"), $dateTime->getTimestamp());
            $requestUrl = sprintf("%s/volumes/%s/snapshots", rtrim(self::API_ENDPOINT, "/"), $id);
            $httpClient = $this->getHttpClient();
            $data = ["name" => $volumeSnapshotName];
            $response = $this->retry(function () use($httpClient, $requestUrl, $data) {
                $response = $httpClient->post($requestUrl, ["body" => json_encode($data)]);
                return $response;
            });
        }
    }
    public function getVolumeSnapshots($id) : array
    {
        $volumeSnapshots = [];
        $requestUrl = sprintf("%s/volumes/%s/snapshots?page=1&per_page=1000", rtrim(self::API_ENDPOINT, "/"), $id);
        $request = new Request("GET", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        $responseStatusCode = $response->getStatusCode();
        if (200 == $responseStatusCode) {
            $responseData = trim((string) $response->getBody());
            $responseData = json_decode($responseData, true);
            if (true === is_array($responseData)) {
                $doVolumeSnapshots = $responseData["snapshots"] ?? [];
                foreach ($doVolumeSnapshots as $doVolumeSnapshot) {
                    $id = $doVolumeSnapshot["id"] ?? '';
                    $name = $doVolumeSnapshot["name"] ?? '';
                    if (!(false === empty($name) && "-clp" == substr($name, -4))) {
                        continue;
                    }
                    $createdAt = true === isset($doVolumeSnapshot["created_at"]) ? new \DateTime($doVolumeSnapshot["created_at"]) : new \DateTime();
                    $size = true === isset($doVolumeSnapshot["size_gigabytes"]) ? (float) $doVolumeSnapshot["size_gigabytes"] : 0.0;
                    $volumeSnapshot = new VolumeSnapshot();
                    $volumeSnapshot->setId($id);
                    $volumeSnapshot->setName($name);
                    $volumeSnapshot->setCreatedAt($createdAt);
                    $volumeSnapshot->setSize($size);
                    $volumeSnapshots[] = $volumeSnapshot;
                }
            }
        }
        return $volumeSnapshots;
    }
    public function deleteVolumeSnapshot($id) : void
    {
        $requestUrl = sprintf("%s/snapshots/%s", rtrim(self::API_ENDPOINT, "/"), $id);
        $request = new Request("DELETE", $requestUrl);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
    }
    public function setToken($token) : void
    {
        $this->token = $token;
    }
    public function getToken() : ?string
    {
        return $this->token;
    }
    public function unsetHttpClient()
    {
        $this->httpClient = null;
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
    public function getMetaDataValue($key) : string
    {
        try {
            $value = '';
            $requestUrl = sprintf("%s/%s", rtrim(self::META_DATA_ENDPOINT, "/"), $key);
            $request = new Request("GET", $requestUrl);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $value = trim((string) $response->getBody());
            }
        } catch (\Exception $e) {
        }
        return $value;
    }
    private function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}