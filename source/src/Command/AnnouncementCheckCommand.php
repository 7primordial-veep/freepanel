<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
class AnnouncementCheckCommand extends BaseCommand
{
    const HTTP_CLIENT_TIMEOUT = 15;
    const ANNOUNCEMENT_REQUEST_URL = "https://announcement.cloudpanel.io/";
    private ?HttpClient $httpClient = null;
    protected function configure() : void
    {
        $this->setName("announcement:check");
        $this->setDescription("clpctl announcement:check");
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $randomNumber = rand(1, 180);
            sleep($randomNumber);
            $this->checkAnnouncement();
            return BaseCommand::SUCCESS;
        } catch (\Exception $e) {
            $logger = $this->getLogger();
            $logger->exception($e);
            $errorMessage = $e->getMessage();
            $output->writeln(sprintf("<error>An error has occurred: \"%s\"</error>", $errorMessage));
            return BaseCommand::FAILURE;
        }
    }
    private function checkAnnouncement() : void
    {
        $httpClient = $this->getHttpClient();
        $request = new Request("GET", self::ANNOUNCEMENT_REQUEST_URL);
        $response = $this->retry(function () use($httpClient, $request) {
            $response = $httpClient->send($request);
            return $response;
        }, 1, 3);
        $responseStatusCode = $response->getStatusCode();
        if (200 == $responseStatusCode) {
            $responseData = trim((string) $response->getBody());
            $responseData = json_decode($responseData, true);
            if (false === empty($responseData) && true === is_array($responseData)) {
                $this->saveAnnouncement($responseData);
            }
        }
    }
    private function saveAnnouncement(array $announcement) : void
    {
        $createdAt = true === isset($announcement["created_at"]) ? trim($announcement["created_at"]) : '';
        $subject = true === isset($announcement["subject"]) ? trim($announcement["subject"]) : '';
        $url = true === isset($announcement["url"]) ? trim($announcement["url"]) : '';
        if (false === empty($createdAt) && false === empty($subject) && false === empty($url)) {
            $hash = md5(implode("|", [$subject, $url]));
            $container = $this->getContainer();
            $announcementManager = $container->get("App\\Entity\\Manager\\AnnouncementManager");
            $existingAnnouncement = $announcementManager->findOneByHash($hash);
            if (true === is_null($existingAnnouncement)) {
                $userManager = $container->get("App\\Entity\\Manager\\UserManager");
                $users = $userManager->findAll();
                $createdAt = new \DateTime($createdAt);
                foreach ($users as $user) {
                    $announcement = $announcementManager->createEntity();
                    $announcement->setCreatedAt($createdAt);
                    $announcement->setUser($user);
                    $announcement->setSubject($subject);
                    $announcement->setUrl($url);
                    $announcementManager->updateEntity($announcement);
                }
            }
        }
    }
    private function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false, "headers" => ["Content-Type" => "application/json"]];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
}