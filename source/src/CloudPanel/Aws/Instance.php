<?php

namespace App\CloudPanel\Aws;

use GuzzleHttp\Psr7\Request;
use App\CloudPanel\Instance as BaseInstance;
class Instance extends BaseInstance
{
    const AWS_INSTANCE_IDENTITY_API_TOKEN_ENDPOINT = "http://169.254.169.254/latest/api/token";
    const AWS_INSTANCE_IDENTITY_ENDPOINT = "http://169.254.169.254/latest/dynamic/instance-identity/document";
    private ?string $instanceId = null;
    private array $instanceData = [];
    private ?string $instanceType = null;
    public function getRegion() : ?string
    {
        if (true === is_null($this->region)) {
            $instanceData = $this->getInstanceData();
            if (true === isset($instanceData["region"]) && false === empty($instanceData["region"])) {
                $this->region = $instanceData["region"];
            }
        }
        return $this->region;
    }
    public function setInstanceId(string $instanceId) : void
    {
        $this->instanceId = $instanceId;
    }
    public function getInstanceId() : string
    {
        if (true === is_null($this->instanceId)) {
            $instanceData = $this->getInstanceData();
            if (true === isset($instanceData["instanceId"]) && false === empty($instanceData["instanceId"])) {
                $this->instanceId = $instanceData["instanceId"];
            }
        }
        return $this->instanceId;
    }
    public function setInstanceType(string $instanceType) : void
    {
        $this->instanceType = $instanceType;
    }
    public function getInstanceType() : string
    {
        if (true === is_null($this->instanceType)) {
            $instanceData = $this->getInstanceData();
            if (true === isset($instanceData["instanceType"]) && false === empty($instanceData["instanceType"])) {
                $this->instanceType = $instanceData["instanceType"];
            }
        }
        return $this->instanceType;
    }
    private function getInstanceData() : array
    {
        if (true === empty($this->instanceData)) {
            $metadataToken = $this->getMetadataToken();
            $request = new Request("GET", self::AWS_INSTANCE_IDENTITY_ENDPOINT, ["X-aws-ec2-metadata-token" => $metadataToken]);
            $httpClient = $this->getHttpClient();
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $this->instanceData = json_decode((string) $response->getBody(), true);
            }
        }
        return $this->instanceData;
    }
    private function getMetadataToken() : ?string
    {
        $metadataToken = null;
        $request = new Request("PUT", self::AWS_INSTANCE_IDENTITY_API_TOKEN_ENDPOINT, ["X-aws-ec2-metadata-token-ttl-seconds" => 21600]);
        $httpClient = $this->getHttpClient();
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        });
        $responseStatusCode = $response->getStatusCode();
        if (200 == $responseStatusCode) {
            $metadataToken = (string) $response->getBody();
        }
        return $metadataToken;
    }
}