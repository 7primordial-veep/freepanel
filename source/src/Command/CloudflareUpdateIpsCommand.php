<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use App\Command\Command as BaseCommand;
use App\System\Command\WriteFileCommand;
use App\System\Command\ServiceReloadCommand;
use App\System\CommandExecutor;
class CloudflareUpdateIpsCommand extends BaseCommand
{
    private const CLOUDFLARE_IPV4_REQUEST_URL = "https://www.cloudflare.com/ips-v4";
    private const CLOUDFLARE_IPV6_REQUEST_URL = "https://www.cloudflare.com/ips-v6";
    private const NGINX_CLOUDFLARE_FILE = "/etc/nginx/cloudflare/ips";
    private const HTTP_CLIENT_TIMEOUT = 10;
    private ?HttpClient $httpClient = null;
    protected function configure() : void
    {
        $this->setName("cloudflare:update:ips");
        $this->setDescription("clpctl cloudflare:update:ips");
        $this->addOption("delay", null, InputOption::VALUE_OPTIONAL, false);
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $delay = (bool) $input->getOption("delay");
            if (true === $delay) {
                sleep(rand(15, 180));
            }
            $this->updateIps();
            $output->writeln("<info>Cloudflare IPs have been updated.</info>");
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $logger = $this->getLogger();
            $logger->exception($e);
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>%s</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function updateIps() : void
    {
        $httpClient = $this->getHttpClient();
        $requestUrls = [self::CLOUDFLARE_IPV4_REQUEST_URL, self::CLOUDFLARE_IPV6_REQUEST_URL];
        $ips = [];
        foreach ($requestUrls as $requestUrl) {
            $request = new Request("GET", $requestUrl);
            $response = $httpClient->send($request);
            $responseStatusCode = $response->getStatusCode();
            if (!(200 == $responseStatusCode)) {
                continue;
            }
            $responseData = trim((string) $response->getBody());
            if (!(false === empty($responseData))) {
                continue;
            }
            $cloudflareIps = explode(PHP_EOL, $responseData);
            if (!(false === empty($cloudflareIps) && true === is_array($cloudflareIps))) {
                continue;
            }
            $this->validateIps($cloudflareIps);
            $ips = array_merge($ips, $cloudflareIps);
        }
        if (false === empty($ips)) {
            $this->writeIpsToFile($ips);
            $this->reloadNginxService();
        }
    }
    private function writeIpsToFile(array $ips) : void
    {
        $commandExecutor = new CommandExecutor();
        $fileContent = '';
        foreach ($ips as $ip) {
            $fileContent .= sprintf("allow %s;", $ip) . PHP_EOL;
        }
        $fileContent .= "deny all;";
        $writeFileCommand = new WriteFileCommand();
        $writeFileCommand->setFile(self::NGINX_CLOUDFLARE_FILE);
        $writeFileCommand->setContent($fileContent);
        $commandExecutor->execute($writeFileCommand);
    }
    private function reloadNginxService()
    {
        if ("dev" != $_ENV["APP_ENV"]) {
            $commandExecutor = new CommandExecutor();
            $reloadServiceCommand = new ServiceReloadCommand();
            $reloadServiceCommand->setServiceName("nginx");
            $commandExecutor->execute($reloadServiceCommand);
        }
    }
    private function validateIps(array $ips) : void
    {
        foreach ($ips as $ip) {
            $isValidIp = $this->validateIp($ip);
            if (!(false === $isValidIp)) {
                continue;
            }
            throw new \Exception(sprintf("IP \"%s\" is not valid.", $ip));
        }
    }
    private function validateIp(string $ip) : bool
    {
        $ipAddress = $ip;
        $ipParts = explode("/", $ipAddress);
        $ip = $ipParts[0] ?? '';
        $netmask = $ipParts[1] ?? '';
        $isIpv6 = substr_count($ipAddress, ":") ? true : false;
        $isValidIp = false;
        if (!(true === $isIpv6)) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $isValidIp = true;
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $isValidIp = true;
        }
        if (true === $isValidIp && false === empty($netmask)) {
            $netmask = (int) $netmask;
            $isNetmaskValid = false;
            if ($netmask < 0) {
                $isNetmaskValid = false;
            }
            if (true === $isIpv6) {
                $isNetmaskValid = $netmask <= 128;
            } else {
                $isNetmaskValid = $netmask <= 32;
            }
            if (false === $isNetmaskValid) {
                $isValidIp = false;
            }
        }
        return $isValidIp;
    }
    private function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
}