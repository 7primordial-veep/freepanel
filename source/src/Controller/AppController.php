<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Psr\Cache\CacheItemPoolInterface as CachePool;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use App\Entity\Manager\AnnouncementManager;
use App\Entity\Manager\ConfigManager;
use App\Entity\Manager\UserManager;
use App\CloudPanel\Environment as CloudPanelEnvironment;
class AppController extends Controller
{
    const HTTP_CLIENT_TIMEOUT = 10;
    const APP_VERSION_CHECK_REQUEST_URL = "https://version.cloudpanel.io/v2";
    public function autologin(Request $request) : Response
    {
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function displayAnnouncement(Request $request, AnnouncementManager $announcementManager) : Response
    {
        $user = $this->getUser();
        $announcement = $announcementManager->findLatestUnreadAnnouncement($user);
        $response = $this->render("Partial/announcement.html.twig", ["announcement" => $announcement]);
        return $response;
    }
    public function setAnnouncementRead(Request $request, AnnouncementManager $announcementManager) : Response
    {
        $user = $this->getUser();
        $response = new Response();
        $announcementId = (int) $request->get("announcementId");
        if (true === $request->isMethod("POST") && false === empty($announcementId)) {
            $announcement = $announcementManager->findOneById($announcementId);
            if (false === is_null($announcement) && $announcement->getUser()->getId() == $user->getId()) {
                $userAnnouncements = $announcementManager->findAllUnreadAnnouncementsForUser($user);
                if (false === empty($userAnnouncements)) {
                    foreach ($userAnnouncements as $announcement) {
                        $announcement->setIsRead(true);
                        $announcementManager->updateEntity($announcement);
                    }
                }
            }
        }
        return $response;
    }
    public function checkAppVersion(Request $request, CachePool $cachePool, ConfigManager $configManager) : Response
    {
        $data = [];
        $releaseChannel = (string) $configManager->get("release_channel");
        $requestUrl = sprintf("%s%s", self::APP_VERSION_CHECK_REQUEST_URL, CloudPanelEnvironment::RELEASE_CHANNEL_TEST == $releaseChannel ? "-test" : '');
        $latestAppVersion = $cachePool->get("app_version", function (ItemInterface $item) use($requestUrl) {
            $item->expiresAfter(3600);
            $appVersion = '';
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false, "headers" => ["User-Agent" => "CloudPanel v2"]];
            try {
                $httpClient = new HttpClient($config);
                $request = new GuzzleRequest("GET", $requestUrl);
                $response = $httpClient->send($request);
                $responseStatusCode = $response->getStatusCode();
                if (200 == $responseStatusCode) {
                    $appVersion = trim((string) $response->getBody());
                }
            } catch (\Exception $e) {
            }
            return $appVersion;
        });
        $currentAppVersion = $this->getParameter("app_version");
        if (false === empty($latestAppVersion) && false === empty($currentAppVersion)) {
            if (version_compare($latestAppVersion, $currentAppVersion) > 0) {
                $data["updateAvailable"] = true;
            }
        }
        return $this->json($data);
    }
    public function footerNavigation(RequestStack $requestStack) : Response
    {
        $mainRequest = $requestStack->getMainRequest();
        $requestUri = $mainRequest->getRequestUri();
        $isAdminArea = true === str_contains($requestUri, "admin");
        $response = $this->render("Partial/footer.html.twig", ["isAdminArea" => $isAdminArea]);
        return $response;
    }
    public function changeLocale(Request $request) : Response
    {
        $response = $this->redirectToReferer($request);
        return $response;
    }
}