<?php

namespace App\Gce;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Google\Service\Compute\Instance as GoogleServiceInstance;
use Google\Service\Compute\Operation as GoogleServiceOperation;
use App\CloudPanel\Gce\Instance as GceInstance;
use App\Util\Retry;
class Client
{
    const META_DATA_HTTP_CLIENT_TIMEOUT = 30;
    const META_DATA_ENDPOINT = "http://metadata/computeMetadata/v1/";
    const GOOGLE_APIS_SCOPE_CLOUD_PLATFORM = "https://www.googleapis.com/auth/cloud-platform";
    private ?HttpClient $metaDataHttpClient = null;
    private ?\Google_Client $gceClient = null;
    private ?GoogleServiceInstance $instance = null;
    private ?GceInstance $gceInstance = null;
    public function __construct()
    {
        $this->gceClient = new \Google_Client();
        $this->gceClient->addScope(self::GOOGLE_APIS_SCOPE_CLOUD_PLATFORM);
    }
    public function setGceInstance(GceInstance $instance) : void
    {
        $this->gceInstance = $instance;
    }
    public function getGceInstance() : ?GceInstance
    {
        return $this->gceInstance;
    }
    public function getGceClient() : ?\Google_Client
    {
        return $this->gceClient;
    }
    public function setAuthConfig(array $config) : void
    {
        $this->gceClient->setAuthConfig($config);
    }
    public function getInstance() : ?GoogleServiceInstance
    {
        if (true === is_null($this->instance)) {
            $project = $this->gceInstance->getProjectId();
            $zone = $this->gceInstance->getZone();
            $instanceId = $this->gceInstance->getInstanceId();
            $service = new \Google_Service_Compute($this->gceClient);
            $this->instance = $this->retry(function () use($service, $project, $zone, $instanceId) {
                $instance = $service->instances->get($project, $zone, $instanceId);
                return $instance;
            });
        }
        return $this->instance;
    }
    public function getInstanceTags() : array
    {
        $instanceTags = [];
        $instance = $this->getInstance();
        $tags = $instance->getTags();
        if (true === isset($tags) && $tags instanceof \Google_Service_Compute_Tags) {
            $instanceTags = $tags->getItems();
        }
        return $instanceTags;
    }
    public function hasInstanceTag(string $name) : bool
    {
        $hasInstanceTag = false;
        $instanceTags = (array) $this->getInstanceTags();
        if (false === empty($instanceTags)) {
            foreach ($instanceTags as $tag) {
                if (!(false === empty($tag) && $name == $tag)) {
                    continue;
                }
                $hasInstanceTag = true;
                break;
            }
        }
        return $hasInstanceTag;
    }
    public function addInstanceTag(string $name) : void
    {
        $hasInstanceTag = $this->hasInstanceTag($name);
        if (false === $hasInstanceTag) {
            $instance = $this->getInstance();
            $tags = $instance->getTags();
            $items = (array) $tags->getItems();
            $items[] = $name;
        }
    }
    public function getSnapshots() : array
    {
        $project = $this->gceInstance->getProjectId();
        $service = new \Google_Service_Compute($this->gceClient);
        $instance = $this->getInstance();
        $snapshots = [];
        if (!(true === isset($instance) && $instance instanceof \Google_Service_Compute_Instance)) {
            throw new \Exception("Instance does not exist.");
        }
        $diskSources = [];
        $disks = (array) $instance->getDisks();
        if (false === empty($disks)) {
            foreach ($disks as $disk) {
                $diskSources[] = $disk->getSource();
            }
        }
        $optParams = [];
        do {
            $snapshotList = $this->retry(function () use($service, $project, $optParams) {
                $snapshotList = $service->snapshots->listSnapshots($project, $optParams);
                return $snapshotList;
            });
            if (true === isset($snapshotList) && $snapshotList instanceof \Google_Service_Compute_SnapshotList) {
                $snapshotListItems = (array) $snapshotList->getItems();
                foreach ($snapshotListItems as $snapshotListItem) {
                    $snapshotListItemSourceDisk = $snapshotListItem->getSourceDisk();
                    if (true === in_array($snapshotListItemSourceDisk, $diskSources)) {
                        $createdAt = $snapshotListItem->getCreationTimestamp();
                        $createdAt = new \DateTime($createdAt);
                        $createdAt->setTimezone(new \DateTimeZone("UTC"));
                        $labels = (array) $snapshotListItem->getLabels();
                        $type = Snapshot::TYPE_AUTOMATED;
                        if (true === isset($labels["type"]) && $labels["type"] == Snapshot::TYPE_MANUAL) {
                            $type = Snapshot::TYPE_MANUAL;
                        }
                        $disk = (array) explode("/", $snapshotListItemSourceDisk);
                        $disk = false === empty($disk) ? end($disk) : '';
                        $snapshot = new Snapshot();
                        $snapshot->setId($snapshotListItem->getId());
                        $snapshot->setName($snapshotListItem->getName());
                        $snapshot->setDisk($disk);
                        $snapshot->setDiskSizeGb($snapshotListItem->getDiskSizeGb());
                        $snapshot->setLabels($labels);
                        $snapshot->setType($type);
                        $snapshot->setStatus($snapshotListItem->getStatus());
                        $snapshot->setCreatedAt($createdAt);
                        $snapshots[] = $snapshot;
                    }
                }
                $optParams["pageToken"] = $snapshotList->getNextPageToken();
            }
        } while (false === empty($optParams["pageToken"]));
        return $snapshots;
    }
    public function getSnapshot($id)
    {
        $project = $this->gceInstance->getProjectId();
        $service = new \Google_Service_Compute($this->gceClient);
        $snapshot = null;
        $optParams = ["filter" => sprintf("id=%s", $id)];
        $snapshotList = $this->retry(function () use($service, $project, $optParams) {
            $snapshotList = $service->snapshots->listSnapshots($project, $optParams);
            return $snapshotList;
        });
        if (true === isset($snapshotList) && $snapshotList instanceof \Google_Service_Compute_SnapshotList) {
            $snapshotListItems = (array) $snapshotList->getItems();
            if (false === empty($snapshotListItems) && true === isset($snapshotListItems[0])) {
                $snapshot = $snapshotListItems[0];
            }
        }
        return $snapshot;
    }
    public function deleteSnapshot($id) : GoogleServiceOperation
    {
        $project = $this->gceInstance->getProjectId();
        $service = new \Google_Service_Compute($this->gceClient);
        $response = $this->retry(function () use($service, $project, $id) {
            $response = $service->snapshots->delete($project, $id);
            return $response;
        });
        return $response;
    }
    public function createDiskSnapshots(string $name, string $type) : void
    {
        $instance = $this->getInstance();
        if (true === isset($instance) && $instance instanceof \Google_Service_Compute_Instance) {
            $disks = (array) $instance->getDisks();
            if (false === empty($disks)) {
                foreach ($disks as $disk) {
                    $diskSource = $disk->getSource();
                    $diskName = (array) explode("/", $diskSource);
                    $diskName = false === empty($diskName) ? end($diskName) : '';
                    $this->createSnapshot($name, $diskName, $type);
                }
            }
        }
    }
    public function createSnapshot($name, $diskName, $type) : GoogleServiceOperation
    {
        $project = $this->gceInstance->getProjectId();
        $instanceUid = $this->gceInstance->getUid();
        $zone = $this->gceInstance->getZone();
        $service = new \Google_Service_Compute($this->gceClient);
        $dateTime = new \DateTime();
        $name = sprintf("%s-%s", $name, $dateTime->getTimestamp());
        $name = substr(strtolower($name), 0, 60);
        $labels = ["created_by" => "cloudpanel", "instance_uid" => $instanceUid, "type" => $type];
        $snapshot = new \Google_Service_Compute_Snapshot();
        $snapshot->setName($name);
        $snapshot->setLabels($labels);
        $response = $this->retry(function () use($service, $project, $zone, $diskName, $snapshot) {
            $response = $service->disks->createSnapshot($project, $zone, $diskName, $snapshot);
            return $response;
        });
        return $response;
    }
    public function getMetaDataInstanceId() : ?string
    {
        $instanceId = $this->getMetaDataValue("instance/id");
        return $instanceId;
    }
    public function getMetaDataInstanceName() : ?string
    {
        $instanceName = $this->getMetaDataValue("instance/name");
        return $instanceName;
    }
    public function getMetaDataMachineType() : ?string
    {
        $machineType = $this->getMetaDataValue("instance/machine-type");
        $machineType = explode("/", $machineType);
        $machineType = array_pop($machineType);
        return $machineType;
    }
    public function getMetaDataIpv4PublicIp() : ?string
    {
        $ipv4PublicIp = $this->getMetaDataValue("instance/network-interfaces/0/access-configs/0/external-ip");
        return $ipv4PublicIp;
    }
    public function getMetaDataZone() : ?string
    {
        $zone = $this->getMetaDataValue("instance/zone");
        $zone = explode("/", $zone);
        $zone = array_pop($zone);
        return $zone;
    }
    public function getMetaDataProjectId() : ?string
    {
        $projectId = $this->getMetaDataValue("project/project-id");
        return $projectId;
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
    private function getMetaDataHttpClient() : HttpClient
    {
        if (true === is_null($this->metaDataHttpClient)) {
            $config = ["timeout" => self::META_DATA_HTTP_CLIENT_TIMEOUT, "verify" => false, "headers" => ["Metadata-Flavor" => "Google"]];
            $this->metaDataHttpClient = new HttpClient($config);
        }
        return $this->metaDataHttpClient;
    }
    private function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}