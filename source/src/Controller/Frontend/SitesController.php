<?php

namespace App\Controller\Frontend;

use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Form\Form;
use Twig\Environment as Twig;
use Doctrine\Common\Collections\ArrayCollection;
use App\Controller\Controller;
use App\Event\EventQueue;
use App\Service\Crypto;
use App\Service\Logger;
use App\Site\Site;
use App\Site\NodejsSite;
use App\Site\StaticSite;
use App\Site\PhpSite;
use App\Site\PythonSite;
use App\Site\ReverseProxySite;
use App\Entity\Site as SiteEntity;
use App\Entity\NodejsSettings;
use App\Entity\PhpSettings;
use App\Entity\PythonSettings;
use App\Entity\BasicAuth as BasicAuthEntity;
use App\Entity\BlockedBot as BlockedBotEntity;
use App\Entity\BlockedIp as BlockedIpEntity;
use App\Entity\Database as DatabaseEntity;
use App\Entity\DatabaseUser as DatabaseUserEntity;
use App\Entity\User as UserEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\CronJob as CronJobEntity;
use App\Entity\FtpUser as FtpUserEntity;
use App\Entity\SshUser as SshUserEntity;
use App\Site\Updater as SiteUpdater;
use App\Site\Updater\NodejsSite as NodejsSiteUpdater;
use App\Site\Updater\StaticSite as StaticSiteUpdater;
use App\Site\Updater\PhpSite as PhpSiteUpdater;
use App\Site\Updater\PythonSite as PythonSiteUpdater;
use App\Site\Updater\ReverseProxySite as ReverseProxySiteUpdater;
use App\Site\Deleter as SiteDeleter;
use App\Site\Deleter\NodejsSite as NodejsSiteDeleter;
use App\Site\Deleter\StaticSite as StaticSiteDeleter;
use App\Site\Deleter\PhpSite as PhpSiteDeleter;
use App\Site\Deleter\PythonSite as PythonSiteDeleter;
use App\Site\Deleter\ReverseProxySite as ReverseProxySiteDeleter;
use App\Database\Manager as DatabaseManager;
use App\Entity\Manager\ConfigManager;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\BasicAuthManager as BasicAuthEntityManager;
use App\Entity\Manager\BlockedIpManager as BlockedIpEntityManager;
use App\Entity\Manager\BlockedBotManager as BlockedBotEntityManager;
use App\Entity\Manager\CertificateManager as CertificateEntityManager;
use App\Entity\Manager\CronJobManager as CronJobEntityManager;
use App\Entity\Manager\DatabaseManager as DatabaseEntityManager;
use App\Entity\Manager\DatabaseUserManager as DatabaseUserEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Entity\Manager\NodejsSettingsManager as NodejsSettingsEntityManager;
use App\Entity\Manager\FtpUserManager as FtpUserEntityManager;
use App\Entity\Manager\SshUserManager as SshUserEntityManager;
use App\Log\LogfileReader;
use App\Log\LogsFinder;
use App\Log\Parser\NginxAccessLogParser;
use App\Log\Parser\NginxErrorLogParser;
use App\Log\Parser\PhpFpmErrorLogParser;
use App\Site\Parser\DomainName as DomainNameParser;
use App\Site\Nginx\Exception\InvalidVhostException;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\LetsEncryptClient;
use App\Site\VarnishCache\Client as VarnishCacheClient;
class SitesController extends Controller
{
    private const FILE_MANAGER_COOKIE_EXPIRATION = 3;
    private ConfigManager $configManager;
    private SiteEntityManager $siteEntityManager;
    private BasicAuthEntityManager $basicAuthEntityManager;
    private BlockedIpEntityManager $blockedIpEntityManager;
    private BlockedBotEntityManager $blockedBotEntityManager;
    private CertificateEntityManager $certificateEntityManager;
    private CronJobEntityManager $cronJobEntityManager;
    private DatabaseEntityManager $databaseEntityManager;
    private DatabaseUserEntityManager $databaseUserEntityManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    private NodejsSettingsEntityManager $nodejsSettingsEntityManager;
    private FtpUserEntityManager $ftpUserEntityManager;
    private SshUserEntityManager $sshUserEntityManager;
    private DomainNameParser $domainNameParser;
    public function __construct(ConfigManager $configManager, SiteEntityManager $siteEntityManager, BasicAuthEntityManager $basicAuthEntityManager, BlockedIpEntityManager $blockedIpEntityManager, BlockedBotEntityManager $blockedBotEntityManager, CertificateEntityManager $certificateEntityManager, CronJobEntityManager $cronJobEntityManager, DatabaseEntityManager $databaseEntityManager, DatabaseUserEntityManager $databaseUserEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, NodejsSettingsEntityManager $nodejsSettingsEntityManager, FtpUserEntityManager $ftpUserEntityManager, SshUserEntityManager $sshUserEntityManager, DomainNameParser $domainNameParser, TranslatorInterface $translator, Logger $logger)
    {
        $this->configManager = $configManager;
        $this->siteEntityManager = $siteEntityManager;
        $this->basicAuthEntityManager = $basicAuthEntityManager;
        $this->blockedIpEntityManager = $blockedIpEntityManager;
        $this->blockedBotEntityManager = $blockedBotEntityManager;
        $this->certificateEntityManager = $certificateEntityManager;
        $this->cronJobEntityManager = $cronJobEntityManager;
        $this->databaseEntityManager = $databaseEntityManager;
        $this->databaseUserEntityManager = $databaseUserEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->nodejsSettingsEntityManager = $nodejsSettingsEntityManager;
        $this->ftpUserEntityManager = $ftpUserEntityManager;
        $this->sshUserEntityManager = $sshUserEntityManager;
        $this->domainNameParser = $domainNameParser;
        parent::__construct($translator, $logger);
    }
    public function index(Request $request) : Response
    {
        $user = $this->getUser();
        $sites = $this->siteEntityManager->getUserSites($user, ["domainName" => "asc"]);
        $response = $this->render("Frontend/Site/index.html.twig", ["user" => $user, "sites" => $sites]);
        return $response;
    }

    public function cloneSite(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (true === is_null($site)) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        if ("POST" === $request->getMethod()) {
            $this->checkCsrfToken($request, "site-clone");
            $newDomain = mb_strtolower(trim((string) $request->request->get("newDomain")));
            $newUser = trim((string) $request->request->get("newUser"));
            $newUserPassword = (string) $request->request->get("newUserPassword");
            $errors = [];
            if (empty($newDomain) || empty($newUser) || empty($newUserPassword)) {
                $errors[] = $this->translator->trans("All fields are required.");
            }
            if (empty($errors)) {
                // Pass the password via env, not argv — keeps it out of /proc and shell history.
                $cmd = sprintf(
                    "sudo -E clpctl site:clone --sourceDomain=%s --newDomain=%s --newUser=%s 2>&1",
                    escapeshellarg($site->getDomainName()),
                    escapeshellarg($newDomain),
                    escapeshellarg($newUser)
                );
                $descs = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
                $env = ["CLPCTL_NEW_USER_PASSWORD" => $newUserPassword] + $_SERVER;
                $proc = proc_open($cmd, $descs, $pipes, null, $env);
                $cliOutput = "";
                if (is_resource($proc)) {
                    $cliOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]);
                    $exit = proc_close($proc);
                } else {
                    $exit = -1;
                }
                if (0 === $exit) {
                    $request->getSession()->getFlashBag()->add("success", $this->translator->trans("Site cloned successfully."));
                    return $this->redirect($this->generateUrl("clp_sites"));
                }
                // Log raw output server-side; surface a generic message to the user.
                $this->logger->exception(new \RuntimeException("site:clone failed (exit {$exit}): " . $cliOutput));
                $errors[] = $this->translator->trans("Site clone failed. Check the system log for details.");
            }
            $this->formErrors = $errors;
        }
        return $this->render("Frontend/Site/clone.html.twig", ["site" => $site, "formErrors" => $this->formErrors]);
    }
    public function fileManager(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            // Bridge: the upstream /file-manager/ backend reads this session key to
            // discover which site user it should suid into.
            $siteUser = $site->getUser();
            $session = $request->getSession();
            $locale = $request->getLocale();
            $data = ["user" => $siteUser, "locale" => $locale];
            $session->set("clp-file-manager", $data);
            $fileManagerUrl = sprintf("%s/file-manager/", rtrim($request->getSchemeAndHttpHost(), "/"));
            $response = $this->render("Frontend/Site/file-manager.html.twig", ["site" => $site, "fileManagerUrl" => $fileManagerUrl, "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function view(Request $request) : Response
    {
        $domainName = $request->get("domainName");
        $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $domainName]));
        return $response;
    }
    public function logs(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $form = $this->createLogsForm($siteEntity);
            $response = $this->render("Frontend/Site/logs.html.twig", ["site" => $site, "formErrors" => $this->formErrors, "form" => $form->createView()]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createLogsForm(SiteEntity $siteEntity) : Form
    {
        $form = $this->createForm("App\\Form\\SiteLogsType", [], ["action" => $this->generateUrl("clp_site_logs", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("update", ButtonType::class, ["attr" => ["class" => "btn btn-lg btn-blue btn-update"], "label" => "Update"]);
        return $form;
    }
    public function logFileContent(Request $request, Twig $twig) : Response
    {
        $data = [];
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $service = trim($request->get("service"));
            $logfileName = trim($request->get("logFile"));
            $numberOfLines = (int) $request->get("numberOfLines");
            if (false === empty($service) && false === empty($logfileName)) {
                try {
                    $service = basename($service);
                    $logfileName = basename($logfileName);
                    $logfile = sprintf("/home/%s/logs/%s/%s", $site->getUser(), $service, $logfileName);
                    $logfileReader = new LogfileReader($logfile);
                    $logLines = $logfileReader->getLines($numberOfLines);
                    $logMessagesHtml = '';
                    $user = $this->getUser();
                    if ("nginx" == $service) {
                        $userTimezone = $user->getTimezone();
                        if ("access.log" == substr($logfileName, 0, 10)) {
                            $nginxAccessLogParser = new NginxAccessLogParser($logLines);
                            $nginxAccessLogParser->setTimezone($userTimezone->getName());
                            $logMessages = $nginxAccessLogParser->parse();
                            $logMessagesHtml = $twig->render("Frontend/Site/Logs/nginx-access-log-messages.html.twig", ["logMessages" => $logMessages]);
                        } else {
                            $nginxErrorLogParser = new NginxErrorLogParser($logLines);
                            $logMessages = $nginxErrorLogParser->parse();
                            $logMessagesHtml = $twig->render("Frontend/Site/Logs/nginx-error-log-messages.html.twig", ["logMessages" => $logMessages]);
                        }
                    }
                    if ("php" == $service) {
                        $phpFpmErrorLogParser = new PhpFpmErrorLogParser($logLines);
                        $logMessages = $phpFpmErrorLogParser->parse();
                        $logMessagesHtml = $twig->render("Frontend/Site/Logs/php-fpm-error-log-messages.html.twig", ["logMessages" => $logMessages]);
                    }
                    $data["logMessagesHtml"] = $logMessagesHtml;
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                }
            }
        }
        $response = $this->json($data);
        return $response;
    }
    public function loadLogfilesForService(Request $request) : Response
    {
        $data = [];
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $service = trim($request->get("service"));
            if (false === empty($service)) {
                try {
                    $service = basename($service);
                    $logfilesDirectory = sprintf("/home/%s/logs/%s/", $site->getUser(), $service);
                    $logsFinder = new LogsFinder($logfilesDirectory);
                    $logfiles = $logsFinder->getLogfiles();
                    if (false === empty($logfiles)) {
                        $data["logfiles"] = $logfiles;
                    }
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                }
            }
        }
        $response = $this->json($data);
        return $response;
    }
    public function cronJobs(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $response = $this->render("Frontend/Site/cron-jobs.html.twig", ["site" => $site, "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function newCronJob(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $cronJobEntity = $this->cronJobEntityManager->createEntity();
            $cronJobEntity->setSite($siteEntity);
            $form = $this->createCronJobForm($cronJobEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCronJobForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/new-cron-job.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createCronJobForm(CronJobEntity $cronJobEntity) : Form
    {
        $siteEntity = $cronJobEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteCronJobType", $cronJobEntity, ["action" => $this->generateUrl("clp_site_cron_job_new", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add Cron Job"]);
        return $form;
    }
    private function handleCronJobForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $cronJobEntity = $form->getData();
                    $siteEntity->addCronJob($cronJobEntity);
                    $site->setCronJobs($siteEntity->getCronJobs());
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateUserCrontab();
                    $eventData = ["site" => $domainName, "crontabExpression" => $cronJobEntity->getCrontabExpression()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_CRON_JOB_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Cron Job has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_cron_jobs", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteCronJob(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "cronjob-delete");
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $id = (int) $request->get("id");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $cronJobEntity = $this->cronJobEntityManager->findOneById($id);
                if (false === is_null($siteEntity) && false === is_null($cronJobEntity)) {
                    $cronJobSiteEntity = $cronJobEntity->getSite();
                    if ($siteEntity->getDomainName() == $cronJobSiteEntity->getDomainName()) {
                        $user = $this->getUser();
                        $siteEntity->removeCronJob($cronJobEntity);
                        $site->setCronJobs($siteEntity->getCronJobs());
                        $this->siteEntityManager->updateEntity($siteEntity);
                        $siteUpdater = $this->getSiteUpdater($site);
                        $siteUpdater->updateUserCrontab();
                        $evenData = ["site" => $domainName, "command" => $cronJobEntity->getCommand()];
                        EventQueue::addEvent(EventQueue::EVENT_SITE_CRON_JOB_DELETE, $user, $evenData, $request);
                        $session->getFlashBag()->set("success", $this->translator->trans("Cron Job has been deleted."));
                        $response = $this->redirect($this->generateUrl("clp_site_cron_jobs", ["domainName" => $site->getDomainName()]));
                        return $response;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function editCronJob(Request $request) : Response
    {
        $id = (int) $request->get("id");
        $site = $this->getSite($request);
        $cronJobEntity = $this->cronJobEntityManager->findOneById($id);
        if (false === is_null($cronJobEntity) && false === is_null($site) && $cronJobEntity->getSite()->getDomainName() == $site->getDomainName()) {
            $form = $this->createCronJobEditForm($cronJobEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCronJobEditForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/edit-cron-job.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createCronJobEditForm(CronJobEntity $cronJobEntity) : Form
    {
        $siteEntity = $cronJobEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteCronJobEditType", $cronJobEntity, ["action" => $this->generateUrl("clp_site_cron_job_edit", ["domainName" => $siteEntity->getDomainName(), "id" => $cronJobEntity->getId()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleCronJobEditForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $cronJobEntity = $form->getData();
                    $siteEntity->addCronJob($cronJobEntity);
                    $site->setCronJobs($siteEntity->getCronJobs());
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateUserCrontab();
                    $eventData = ["site" => $domainName, "crontabExpression" => $cronJobEntity->getCrontabExpression()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_CRON_JOB_EDIT, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Cron Job has been saved."));
                    $response = $this->redirect($this->generateUrl("clp_site_cron_jobs", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function users(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $response = $this->render("Frontend/Site/users.html.twig", ["site" => $site, "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function newFtpUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $ftpUserEntity = $this->ftpUserEntityManager->createEntity();
            $ftpUserEntity->setSite($siteEntity);
            $form = $this->createFtpUserForm($ftpUserEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleFtpUserForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/new-ftp-user.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createFtpUserForm(FtpUserEntity $ftpUserEntity) : Form
    {
        $siteEntity = $ftpUserEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteFtpUserType", $ftpUserEntity, ["action" => $this->generateUrl("clp_site_ftp_user_new", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add User"]);
        return $form;
    }
    private function handleFtpUserForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $ftpUserEntity = $form->getData();
                    $ftpUserName = $ftpUserEntity->getUserName();
                    $ftpUserPassword = $form->get("password")->getData();
                    $ftpUserEntity->setPassword($ftpUserPassword);
                    $siteEntity->addFtpUser($ftpUserEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->createFtpUser($ftpUserEntity);
                    $eventData = ["site" => $domainName, "userName" => $ftpUserName, "homeDirectory" => $ftpUserEntity->getHomeDirectory()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_FTP_USER_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("User has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_users", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function editFtpUser(Request $request) : Response
    {
        $userName = $request->get("userName");
        $site = $this->getSite($request);
        $ftpUserEntity = $this->ftpUserEntityManager->findOneByUserName($userName);
        if (false === is_null($ftpUserEntity) && false === is_null($site) && $ftpUserEntity->getSite()->getDomainName() == $site->getDomainName()) {
            $form = $this->createFtpUserEditForm($ftpUserEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleFtpUserEditForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/edit-ftp-user.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createFtpUserEditForm(FtpUserEntity $ftpUserEntity) : Form
    {
        $siteEntity = $ftpUserEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteFtpUserEditType", $ftpUserEntity, ["action" => $this->generateUrl("clp_site_ftp_user_edit", ["domainName" => $siteEntity->getDomainName(), "userName" => $ftpUserEntity->getUserName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleFtpUserEditForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $ftpUserEntity = $form->getData();
                    $ftpUserName = $ftpUserEntity->getUserName();
                    $ftpUserPassword = $form->get("password")->getData();
                    $ftpUserHomeDirectory = $ftpUserEntity->getHomeDirectory();
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->changeUserHomeDirectory($ftpUserName, $ftpUserHomeDirectory);
                    if (false === empty($ftpUserPassword)) {
                        $siteUpdater->changeUserPassword($ftpUserName, $ftpUserPassword);
                    }
                    $eventData = ["site" => $domainName, "userName" => $ftpUserName, "homeDirectory" => $ftpUserHomeDirectory];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_FTP_USER_EDIT, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("User has been saved."));
                    $response = $this->redirect($this->generateUrl("clp_site_users", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteFtpUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "user-delete");
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $userName = $request->get("userName");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $ftpUserEntity = $this->ftpUserEntityManager->findOneByUserName($userName);
                if (false === is_null($siteEntity) && false === empty($userName) && false === is_null($ftpUserEntity)) {
                    $ftpUserSiteEntity = $ftpUserEntity->getSite();
                    if ($siteEntity->getDomainName() == $ftpUserSiteEntity->getDomainName()) {
                        $user = $this->getUser();
                        $userName = $ftpUserEntity->getUserName();
                        $siteEntity->removeFtpUser($ftpUserEntity);
                        $this->siteEntityManager->updateEntity($siteEntity);
                        $siteUpdater = $this->getSiteUpdater($site);
                        $siteUpdater->deleteUser($userName, false);
                        $evenData = ["site" => $domainName, "userName" => $userName];
                        EventQueue::addEvent(EventQueue::EVENT_SITE_FTP_USER_DELETE, $user, $evenData, $request);
                        $session->getFlashBag()->set("success", $this->translator->trans("User has been deleted."));
                        $response = $this->redirect($this->generateUrl("clp_site_users", ["domainName" => $site->getDomainName()]));
                        return $response;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function newSshUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $sshUserEntity = $this->sshUserEntityManager->createEntity();
            $sshUserEntity->setSite($siteEntity);
            $form = $this->createSshUserForm($sshUserEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleSshUserForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/new-ssh-user.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createSshUserForm(SshUserEntity $sshUserEntity) : Form
    {
        $siteEntity = $sshUserEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteSshUserType", $sshUserEntity, ["action" => $this->generateUrl("clp_site_ssh_user_new", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add User"]);
        return $form;
    }
    private function handleSshUserForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $sshUserEntity = $form->getData();
                    $sshUserName = $sshUserEntity->getUserName();
                    $sshUserPassword = $form->get("password")->getData();
                    $sshUserEntity->setPassword($sshUserPassword);
                    $sshKeys = $sshUserEntity->getSshKeys();
                    $siteEntity->addSshUser($sshUserEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->createSshUser($sshUserEntity);
                    if (false === empty($sshKeys)) {
                        $siteUpdater->updateUserSShKeys($sshUserName, $sshKeys);
                    }
                    $eventData = ["site" => $domainName, "userName" => $sshUserName, "sshKeys" => $sshKeys];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_SSH_USER_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("User has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_users", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function editSshUser(Request $request) : Response
    {
        $userName = $request->get("userName");
        $site = $this->getSite($request);
        $sshUserEntity = $this->sshUserEntityManager->findOneByUserName($userName);
        if (false === is_null($sshUserEntity) && false === is_null($site) && $sshUserEntity->getSite()->getDomainName() == $site->getDomainName()) {
            $form = $this->createSshUserEditForm($sshUserEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleSshUserEditForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/edit-ssh-user.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createSshUserEditForm(SshUserEntity $sshUserEntity) : Form
    {
        $siteEntity = $sshUserEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteSshUserEditType", $sshUserEntity, ["action" => $this->generateUrl("clp_site_ssh_user_edit", ["domainName" => $siteEntity->getDomainName(), "userName" => $sshUserEntity->getUserName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleSshUserEditForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $sshUserEntity = $form->getData();
                    $sshUserName = $sshUserEntity->getUserName();
                    $sshUserPassword = $form->get("password")->getData();
                    $sshKeys = false === is_null($sshUserEntity->getSshKeys()) ? $sshUserEntity->getSshKeys() : '';
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    if (false === empty($sshUserPassword)) {
                        $siteUpdater->changeUserPassword($sshUserName, $sshUserPassword);
                    }
                    $siteUpdater->updateUserSShKeys($sshUserName, $sshKeys);
                    $eventData = ["site" => $domainName, "userName" => $sshUserName, "sshKeys" => $sshKeys];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_SSH_USER_EDIT, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("User has been saved."));
                    $response = $this->redirect($this->generateUrl("clp_site_users", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteSshUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "user-delete");
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $userName = $request->get("userName");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $sshUserEntity = $this->sshUserEntityManager->findOneByUserName($userName);
                if (false === is_null($siteEntity) && false === empty($userName) && false === is_null($sshUserEntity)) {
                    $sshUserSiteEntity = $sshUserEntity->getSite();
                    if ($siteEntity->getDomainName() == $sshUserSiteEntity->getDomainName()) {
                        $user = $this->getUser();
                        $userName = $sshUserEntity->getUserName();
                        $siteEntity->removeSshUser($sshUserEntity);
                        $this->siteEntityManager->updateEntity($siteEntity);
                        $siteUpdater = $this->getSiteUpdater($site);
                        $siteUpdater->deleteUser($userName, true);
                        $evenData = ["site" => $domainName, "userName" => $userName];
                        EventQueue::addEvent(EventQueue::EVENT_SITE_SSH_USER_DELETE, $user, $evenData, $request);
                        $session->getFlashBag()->set("success", $this->translator->trans("User has been deleted."));
                        $response = $this->redirect($this->generateUrl("clp_site_users", ["domainName" => $site->getDomainName()]));
                        return $response;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function security(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $cloudflareData = ["allowTrafficFromCloudflareOnly" => $siteEntity->allowTrafficFromCloudflareOnly()];
            $securityHeadersData = $siteEntity->getSecurityHeadersConfig() + [
                'cspPreset' => 'off', 'cspCustom' => '', 'permissionsPreset' => 'off',
                'frameOptions' => 'SAMEORIGIN', 'referrerPolicy' => 'strict-origin-when-cross-origin',
                'nosniff' => true, 'hsts' => false, 'hstsMaxAge' => 31536000,
                'hstsIncludeSubdomains' => false, 'hstsPreload' => false,
            ];
            $securityHeadersForm = $this->createForm("App\\Form\\SiteSecurityHeadersType", $securityHeadersData, [
                "action" => $this->generateUrl("clp_site_security_headers", ["domainName" => $siteEntity->getDomainName()]),
                "method" => "POST",
            ]);
            $securityHeadersForm->add("submit", \Symfony\Component\Form\Extension\Core\Type\SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
            $blockedBots = $site->getBlockedBots();
            $blockedIps = $site->getBlockedIps();
            $basicAuthEntity = $site->getBasicAuth();
            if (true === is_null($basicAuthEntity)) {
                $basicAuthEntity = $this->basicAuthEntityManager->createEntity();
            }
            $basicAuthEntity->setSite($siteEntity);
            $basicAuthForm = $this->createBasicAuthForm($basicAuthEntity);
            $cloudflareForm = $this->createCloudflareForm($siteEntity, $cloudflareData);
            if (true === $request->isMethod("POST")) {
                $basicAuthForm->handleRequest($request);
                $cloudflareForm->handleRequest($request);
                if (true === $basicAuthForm->isSubmitted()) {
                    $response = $this->handleBasicAuthForm($request, $basicAuthForm, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
                if (true === $cloudflareForm->isSubmitted()) {
                    $response = $this->handleCloudflareForm($request, $cloudflareForm, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/security.html.twig", ["site" => $site, "blockedIps" => $blockedIps, "blockedBots" => $blockedBots, "basicAuthForm" => $basicAuthForm->createView(), "cloudflareForm" => $cloudflareForm->createView(), "securityHeadersForm" => $securityHeadersForm->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createCloudflareForm(SiteEntity $siteEntity, array $data) : Form
    {
        $form = $this->createForm("App\\Form\\SiteCloudflareType", $data, ["action" => $this->generateUrl("clp_site_security", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createBasicAuthForm(BasicAuthEntity $basicAuthEntity) : Form
    {
        $siteEntity = $basicAuthEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteBasicAuthType", $basicAuthEntity, ["action" => $this->generateUrl("clp_site_security", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleBasicAuthForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $basicAuthEntity = $form->getData();
                    $siteEntity->setBasicAuth($basicAuthEntity);
                    $site->setBasicAuth($basicAuthEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->createBasicAuthFile($basicAuthEntity);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $domainName, "active" => true === $basicAuthEntity->getIsActive() ? "1" : "0", "userName" => $basicAuthEntity->getUserName(), "whitelistedIps" => $basicAuthEntity->getWhitelistedIps()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_BASIC_AUTHENTIFICATION_SETTINGS, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Basic Authentication settings have been saved."));
                    $response = $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleCloudflareForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $allowTrafficFromCloudflareOnly = $form->get("allowTrafficFromCloudflareOnly")->getData();
                    $siteEntity->setAllowTrafficFromCloudflareOnly($allowTrafficFromCloudflareOnly);
                    $site->setAllowTrafficFromCloudflareOnly($allowTrafficFromCloudflareOnly);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $domainName, "allowTrafficFromCloudflareOnly" => true === $allowTrafficFromCloudflareOnly ? "1" : "0"];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_CLOUDFLARE_SETTINGS, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Cloudflare settings have been saved."));
                    $response = $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function securityHeaders(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (true === is_null($site)) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $domainName = $site->getDomainName();
        $siteEntity = $this->getSiteEntity($domainName);
        if (true === is_null($siteEntity)) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $session = $request->getSession();
        if (false === $request->isMethod("POST")) {
            return $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $domainName]));
        }
        try {
            $data = $request->request->all();
            $payload = $data["site_security_headers"] ?? [];
            $config = [
                'cspPreset' => $payload['cspPreset'] ?? 'off',
                'cspCustom' => $payload['cspCustom'] ?? '',
                'permissionsPreset' => $payload['permissionsPreset'] ?? 'off',
                'frameOptions' => $payload['frameOptions'] ?? 'SAMEORIGIN',
                'referrerPolicy' => $payload['referrerPolicy'] ?? 'strict-origin-when-cross-origin',
                'nosniff' => isset($payload['nosniff']),
                'hsts' => isset($payload['hsts']),
                'hstsMaxAge' => (int) ($payload['hstsMaxAge'] ?? 31536000),
                'hstsIncludeSubdomains' => isset($payload['hstsIncludeSubdomains']),
                'hstsPreload' => isset($payload['hstsPreload']),
            ];
            $siteEntity->setSecurityHeaders(json_encode($config));
            if (method_exists($site, 'setSecurityHeaders')) {
                $site->setSecurityHeaders(json_encode($config));
            }
            $this->siteEntityManager->updateEntity($siteEntity);
            $siteUpdater = $this->getSiteUpdater($site);
            $siteUpdater->updateNginxVhost();
            $siteUpdater->reloadNginxService();
            $session->getFlashBag()->set("success", $this->translator->trans("Security headers have been saved."));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $domainName]));
    }
    public function blockedCountries(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (true === is_null($site)) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $siteEntity = $this->getSiteEntity($site->getDomainName());
        if (true === is_null($siteEntity)) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $data = ["blockedCountries" => $siteEntity->getBlockedCountries()];
        $form = $this->createForm("App\\Form\\SiteBlockedCountriesType", $data, ["action" => $this->generateUrl("clp_site_security_blocked_countries", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST"]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted() && true === $form->isValid()) {
                try {
                    $session = $request->getSession();
                    $user = $this->getUser();
                    $value = $form->get("blockedCountries")->getData();
                    $siteEntity->setBlockedCountries(true === empty($value) ? null : $value);
                    $site->setBlockedCountries($siteEntity->getBlockedCountries());
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $siteEntity->getDomainName(), "blockedCountries" => (string) $siteEntity->getBlockedCountries()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_BLOCKED_COUNTRIES_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Blocked countries have been saved."));
                    return $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $siteEntity->getDomainName()]));
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                    $request->getSession()->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
                }
            } else {
                $this->formErrors = $this->getErrorMessages($form);
            }
        }
        return $this->render("Frontend/Site/blocked-countries.html.twig", ["site" => $site, "form" => $form->createView(), "blockedCountries" => $siteEntity->getBlockedCountries(), "formErrors" => $this->formErrors]);
    }
    public function newBlockedIp(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $blockedIpEntity = $this->blockedIpEntityManager->createEntity();
            $blockedIpEntity->setSite($siteEntity);
            $form = $this->createBlockedIpForm($blockedIpEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleBlockedIpForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/new-blocked-ip.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createBlockedIpForm(BlockedIpEntity $blockedIpEntity) : Form
    {
        $siteEntity = $blockedIpEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteBlockedIPType", $blockedIpEntity, ["action" => $this->generateUrl("clp_site_security_blocked_ip_new", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add IP"]);
        return $form;
    }
    private function handleBlockedIpForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $blockedIp = $form->getData();
                    $siteEntity->addBlockedIp($blockedIp);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $domainName, "ip" => $blockedIp->getIp()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_BLOCKED_IP_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("IP has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteBlockedIp(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "ip-delete");
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $id = (int) $request->get("id");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $blockedIpEntity = $this->blockedIpEntityManager->findOneById($id);
                if (false === is_null($siteEntity) && false === empty($id) && false === is_null($blockedIpEntity) && $siteEntity == $blockedIpEntity->getSite()) {
                    $user = $this->getUser();
                    $siteEntity->removeBlockedIp($blockedIpEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $domainName, "ip" => $blockedIpEntity->getIp()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_BLOCKED_IP_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("IP has been deleted."));
                    $response = $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $site->getDomainName()]));
                    return $response;
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function newBlockedBot(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $blockedBotEntity = $this->blockedBotEntityManager->createEntity();
            $blockedBotEntity->setSite($siteEntity);
            $form = $this->createBlockedBotForm($blockedBotEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleBlockedBotForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/new-blocked-bot.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createBlockedBotForm(BlockedBotEntity $blockedBotEntity) : Form
    {
        $siteEntity = $blockedBotEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteBlockedBotType", $blockedBotEntity, ["action" => $this->generateUrl("clp_site_security_blocked_bot_new", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add Bot"]);
        return $form;
    }
    private function handleBlockedBotForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $blockedBot = $form->getData();
                    $siteEntity->addBlockedBot($blockedBot);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $domainName, "name" => $blockedBot->getName()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_BLOCKED_BOT_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Bot has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteBlockedBot(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "bot-delete");
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $id = (int) $request->get("id");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $blockedBotEntity = $this->blockedBotEntityManager->findOneById($id);
                if (false === is_null($siteEntity) && false === empty($id) && false === is_null($blockedBotEntity) && $siteEntity == $blockedBotEntity->getSite()) {
                    $user = $this->getUser();
                    $siteEntity->removeBlockedBot($blockedBotEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhost();
                    $siteUpdater->reloadNginxService();
                    $eventData = ["site" => $domainName, "name" => $blockedBotEntity->getName()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_BLOCKED_BOT_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Bot has been deleted."));
                    $response = $this->redirect($this->generateUrl("clp_site_security", ["domainName" => $site->getDomainName()]));
                    return $response;
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function certificates(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $installedCertificate = $site->getCertificate();
            $certificates = $site->getCertificates();
            $response = $this->render("Frontend/Site/certificates.html.twig", ["site" => $site, "installedCertificate" => $installedCertificate, "certificates" => $certificates]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function importCertificate(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $certificateEntity = $this->certificateEntityManager->createEntity();
            $certificateEntity->setType(CertificateEntity::TYPE_IMPORTED);
            $certificateEntity->setSite($siteEntity);
            $form = $this->createImportCertificateForm($certificateEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleImportCertificateForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/import-certificate.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createImportCertificateForm(CertificateEntity $certificateEntity) : Form
    {
        $siteEntity = $certificateEntity->getSite();
        $form = $this->createForm("App\\Form\\SiteImportCertificateType", $certificateEntity, ["action" => $this->generateUrl("clp_site_certificate_import", ["domainName" => $siteEntity->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Import and Install"]);
        return $form;
    }
    private function handleImportCertificateForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $certificateEntity = $form->getData();
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->installCertificate($certificateEntity);
                    $siteEntity->setCertificate($certificateEntity);
                    $siteEntity->addCertificate($certificateEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $evenData = ["site" => $domainName];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_CERTIFICATE_INSTALL, $user, $evenData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Certificate has been installed."));
                    $response = $this->redirect($this->generateUrl("clp_site_certificates", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function newLetsEncryptCertificate(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $domainName = $site->getDomainName();
            $form = $this->createLetsEncryptCertificateForm($domainName);
            $domains = [];
            if (!(true === $request->isMethod("POST"))) {
                $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
                $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
                $subdomain = $resolvedDomainName->subDomain()->toString();
                $subdomain = false === empty($subdomain) ? $subdomain : null;
                if (true === is_null($subdomain) || "www" == $subdomain) {
                    $domains = [$registrableDomain, sprintf("www.%s", $registrableDomain)];
                }
            }
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $domains = (array) $request->get("domains");
                $domains = array_filter($domains, function ($value) {
                    return false === empty(trim($value));
                });
                $domains = array_map("trim", $domains);
                $domains = array_unique($domains);
                $response = $this->handleLetsEncryptCertificateForm($request, $form, $site, $domains);
                if (false === is_null($response)) {
                    return $response;
                }
            }
            $response = $this->render("Frontend/Site/new-lets-encrypt-certificate.html.twig", ["site" => $site, "domains" => $domains, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createLetsEncryptCertificateForm(string $domainName) : Form
    {
        $form = $this->createForm("App\\Form\\SiteLetsEncryptCertificateType", [], ["action" => $this->generateUrl("clp_site_lets_encrypt_certificate_new", ["domainName" => $domainName]), "method" => "POST", "attr" => ["id" => "create-lets-encrypt-certificate-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Create and Install"]);
        return $form;
    }
    private function handleLetsEncryptCertificateForm(Request $request, Form $form, Site $site, array $domains)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (!(false === is_null($siteEntity))) {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                    return $response;
                }
                $user = $this->getUser();
                $letsEncryptPrivateKey = $this->configManager->get("le_private_key");
                $privateKey = new PrivateKey($letsEncryptPrivateKey);
                $letsEncryptClient = new LetsEncryptClient($privateKey);
                $letsEncryptClient->setDryRun(true);
                $letsEncryptClient->registerAccount();
                $certificateOrder = $letsEncryptClient->requestOrder($domains);
                $siteUpdater = $this->getSiteUpdater($site);
                $siteUpdater->deleteLetsEncryptChallengeDirectory();
                $siteUpdater->createLetsEncryptChallengeFiles($certificateOrder);
                $validationErrors = $letsEncryptClient->validateDomains($certificateOrder);
                if (true === empty($validationErrors)) {
                    $letsEncryptClient = new LetsEncryptClient($privateKey);
                    $letsEncryptClient->registerAccount();
                    $certificateOrder = $letsEncryptClient->requestOrder($domains);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->deleteLetsEncryptChallengeDirectory();
                    $siteUpdater->createLetsEncryptChallengeFiles($certificateOrder);
                    $validationErrors = $letsEncryptClient->validateDomains($certificateOrder);
                }
                if (true === empty($validationErrors)) {
                    $distinguishedNameDomains = $domains;
                    $commonName = array_shift($distinguishedNameDomains);
                    $distinguishedName = new DistinguishedName($commonName, $distinguishedNameDomains);
                    $rsaKeyGenerator = new RsaKeyGenerator();
                    $privateKey = $rsaKeyGenerator->generatePrivateKey();
                    $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
                    $csr = $csrGenerator->generate();
                    $certificate = $letsEncryptClient->finalizeOrder($certificateOrder, $privateKey, $csr);
                    $certificateEntity = $this->certificateEntityManager->createEntity();
                    $certificateEntity->setType(CertificateEntity::TYPE_LETS_ENCRYPT);
                    $certificateEntity->setSite($siteEntity);
                    $certificateEntity->setCsr($certificate->getCsr());
                    $certificateEntity->setPrivateKey($certificate->getPrivateKey());
                    $certificateEntity->setCertificate($certificate->getCertificate());
                    $certificateEntity->setCertificateChain($certificate->getCertificateChain());
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->installCertificate($certificateEntity);
                    $siteEntity->setCertificate($certificateEntity);
                    $siteEntity->addCertificate($certificateEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $evenData = ["site" => $domainName];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_CERTIFICATE_INSTALL, $user, $evenData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Certificate has been installed."));
                    $response = $this->redirect($this->generateUrl("clp_site_certificates", ["domainName" => $site->getDomainName()]));
                    return $response;
                } else {
                    foreach ($validationErrors as $domain => $validationErrorMessage) {
                        $form->addError(new FormError(sprintf("%s: %s", $domain, $validationErrorMessage)));
                    }
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            } finally {
                if (true === isset($siteUpdater)) {
                    $siteUpdater->deleteLetsEncryptChallengeDirectory();
                }
            }
        }
        $this->formErrors = $this->getErrorMessages($form);
    }
    public function installCertificate(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $uid = $request->get("uid");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $certificateEntity = $this->certificateEntityManager->findOneByUid($uid);
                if (false === is_null($siteEntity) && false === empty($uid) && false === is_null($certificateEntity)) {
                    $certificateSiteEntity = $certificateEntity->getSite();
                    if ($siteEntity->getDomainName() == $certificateSiteEntity->getDomainName() && $siteEntity->getCertificate() != $certificateSiteEntity) {
                        $user = $this->getUser();
                        $siteUpdater = $this->getSiteUpdater($site);
                        $siteUpdater->installCertificate($certificateEntity);
                        $siteEntity->setCertificate($certificateEntity);
                        $this->siteEntityManager->updateEntity($siteEntity);
                        $evenData = ["site" => $domainName];
                        EventQueue::addEvent(EventQueue::EVENT_SITE_CERTIFICATE_INSTALL, $user, $evenData, $request);
                        $session->getFlashBag()->set("success", $this->translator->trans("Certificate has been installed."));
                        $response = $this->redirect($this->generateUrl("clp_site_certificates", ["domainName" => $site->getDomainName()]));
                        return $response;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function deleteCertificate(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "certificate-delete");
        if (false === is_null($site)) {
            try {
                $session = $request->getSession();
                $uid = $request->get("uid");
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                $certificateEntity = $this->certificateEntityManager->findOneByUid($uid);
                if (false === is_null($siteEntity) && false === empty($uid) && false === is_null($certificateEntity) && false === $certificateEntity->getDefaultCertificate()) {
                    $certificateSiteEntity = $certificateEntity->getSite();
                    if ($siteEntity->getDomainName() == $certificateSiteEntity->getDomainName() && $siteEntity->getCertificate() != $certificateSiteEntity) {
                        $user = $this->getUser();
                        $siteEntity->removeCertificate($certificateEntity);
                        $this->siteEntityManager->updateEntity($siteEntity);
                        $evenData = ["site" => $domainName];
                        EventQueue::addEvent(EventQueue::EVENT_SITE_CERTIFICATE_DELETE, $user, $evenData, $request);
                        $session->getFlashBag()->set("success", $this->translator->trans("Certificate has been deleted."));
                        $response = $this->redirect($this->generateUrl("clp_site_certificates", ["domainName" => $site->getDomainName()]));
                        return $response;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function databases(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $databases = $site->getDatabases();
            $databaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
            $numberOfDatabaseUsers = 0;
            foreach ($databases as $database) {
                $numberOfDatabaseUsers += count($database->getUsers());
            }
            $response = $this->render("Frontend/Site/databases.html.twig", ["site" => $site, "databases" => $databases, "numberOfDatabaseUsers" => $numberOfDatabaseUsers, "databaseServer" => $databaseServerEntity]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function newDatabase(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            $databaseEntity = $this->databaseEntityManager->createEntity();
            $databaseEntity->setSite($siteEntity);
            $form = $this->createDatabaseForm($site, $databaseEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleDatabaseForm($request, $form, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/new-database.html.twig", ["site" => $site, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createDatabaseForm(Site $site, DatabaseEntity $databaseEntity) : Form
    {
        $form = $this->createForm("App\\Form\\SiteDatabaseType", $databaseEntity, ["action" => $this->generateUrl("clp_site_database_new", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add Database"]);
        return $form;
    }
    private function handleDatabaseForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $databaseEntity = $form->getData();
                    $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
                    $databaseManager = new DatabaseManager($activeDatabaseServerEntity);
                    $databaseEntity->setDatabaseServer($activeDatabaseServerEntity);
                    $databaseEntity->setSite($siteEntity);
                    $databaseUserName = $form->get("userName")->getData();
                    $databaseUserPassword = $form->get("userPassword")->getData();
                    $databaseUserEntity = $this->databaseUserEntityManager->createEntity();
                    $databaseUserEntity->setUserName($databaseUserName);
                    $databaseUserEntity->setPassword(Crypto::encrypt($databaseUserPassword));
                    $databaseUserEntity->setPermissions(DatabaseUserEntity::PERMISSIONS_READ_WRITE);
                    $databaseUserEntity->setDatabase($databaseEntity);
                    $databaseEntity->addUser($databaseUserEntity);
                    $siteEntity->addDatabase($databaseEntity);
                    $databaseManager->createDatabase($databaseEntity);
                    $databaseManager->createUser($databaseUserEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "database" => $databaseEntity->getName(), "databaseUserName" => $databaseUserEntity->getUserName()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_DATABASE_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Database has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_site_database_new", ["domainName" => $site->getDomainName()]));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteDatabase(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "database-delete");
        if (false === is_null($site)) {
            $databaseName = $request->get("databaseName");
            $databaseEntity = $this->databaseEntityManager->findOneByName($databaseName);
            $siteEntity = $this->getSiteEntity($site->getDomainName());
            if (false === is_null($databaseEntity) && $site->getDomainName() == $databaseEntity->getSite()->getDomainName() && false === is_null($siteEntity)) {
                try {
                    $user = $this->getUser();
                    $session = $request->getSession();
                    $domainName = $site->getDomainName();
                    $databaseServerEntity = $databaseEntity->getDatabaseServer();
                    $databaseManager = new DatabaseManager($databaseServerEntity);
                    $databaseManager->deleteDatabase($databaseEntity, true);
                    $siteEntity->removeDatabase($databaseEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "database" => $databaseEntity->getName()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_DATABASE_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Database has been deleted."));
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                    $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
                }
                $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
                return $response;
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function phpMyAdmin(Request $request) : Response
    {
        $domainName = $request->get("domainName");
        $session = $request->getSession();
        if (!(false === is_null($domainName))) {
            $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
            if (false === is_null($activeDatabaseServerEntity)) {
                $phpMyAdminUrl = sprintf("https://%s:%s/phpmyadmin/login.php", $request->getHost(), $request->getPort());
                $data = ["host" => $activeDatabaseServerEntity->getHost(), "port" => $activeDatabaseServerEntity->getPort()];
                $session->set("clp-pma", $data);
                $response = new RedirectResponse($phpMyAdminUrl);
                $response->headers->clearCookie("clp-pma");
                $response->headers->clearCookie("phpMyAdmin_https");
                $response->headers->clearCookie("pmaAuth-1_https");
                $response->headers->clearCookie("SignonSession");
                return $response;
            }
        }
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $databaseUserName = $request->get("databaseUserName");
            $databaseUserEntity = $this->databaseUserEntityManager->findOneByUserName($databaseUserName);
            $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
            if (false === is_null($activeDatabaseServerEntity) && false === is_null($databaseUserEntity)) {
                $databaseEntity = $databaseUserEntity->getDatabase();
                if (false === is_null($databaseEntity) && $site->getDomainName() == $databaseEntity->getSite()->getDomainName()) {
                    $phpMyAdminUrl = sprintf("https://%s:%s/phpmyadmin/login.php", $request->getHost(), $request->getPort());
                    $data = ["host" => $activeDatabaseServerEntity->getHost(), "userName" => $databaseUserEntity->getUserName(), "password" => $databaseUserEntity->getDecryptedPassword(), "port" => $activeDatabaseServerEntity->getPort()];
                    $session->set("clp-pma", $data);
                    $response = new RedirectResponse($phpMyAdminUrl);
                    return $response;
                }
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function phpMyAdminLogout(Request $request)
    {
        $response = $this->redirect($this->generateUrl("clp_sites"));
        $response->headers->clearCookie("clp-pma");
        $response->headers->clearCookie("phpMyAdmin_https");
        $response->headers->clearCookie("pmaAuth-1_https");
        $response->headers->clearCookie("SignonSession");
        return $response;
    }
    public function newDatabaseUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $databases = $site->getDatabases();
            $domainName = $site->getDomainName();
            $siteEntity = $this->getSiteEntity($domainName);
            if (count($databases) && false === is_null($siteEntity)) {
                $databaseUserEntity = $this->databaseUserEntityManager->createEntity();
                $databaseUserEntity->setSite($siteEntity);
                $form = $this->createDatabaseUserForm($site, $databaseUserEntity);
                if (true === $request->isMethod("POST")) {
                    $form->handleRequest($request);
                    if (true === $form->isSubmitted()) {
                        $response = $this->handleDatabaseUserForm($request, $form, $site);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $response = $this->render("Frontend/Site/new-database-user.html.twig", ["site" => $site, "databaseUser" => $databaseUserEntity, "databases" => $databases, "form" => $form->createView(), "formErrors" => $this->formErrors]);
            } else {
                $session = $request->getSession();
                $session->getFlashBag()->set("danger", $this->translator->trans("No database found; create a database first."));
                $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
            }
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createDatabaseUserForm(Site $site, DatabaseUserEntity $databaseUserEntity) : Form
    {
        $form = $this->createForm("App\\Form\\SiteDatabaseUserType", $databaseUserEntity, ["action" => $this->generateUrl("clp_site_database_user_new", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add Database User"]);
        return $form;
    }
    private function handleDatabaseUserForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $databaseUserEntity = $form->getData();
                    $encryptedPassword = Crypto::encrypt($databaseUserEntity->getPassword());
                    $databaseUserEntity->setPassword($encryptedPassword);
                    $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
                    $databaseManager = new DatabaseManager($activeDatabaseServerEntity);
                    $databaseManager->createUser($databaseUserEntity);
                    $this->databaseUserEntityManager->updateEntity($databaseUserEntity);
                    $databaseEntity = $databaseUserEntity->getDatabase();
                    $databaseName = $databaseEntity->getName();
                    $eventData = ["site" => $domainName, "databaseUserName" => $databaseUserEntity->getUserName(), "permissions" => $databaseUserEntity->getPermissions(), "database" => $databaseName];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_DATABASE_USER_ADD, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Database User has been added."));
                    $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_site_database_user_new", ["domainName" => $site->getDomainName()]));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function editDatabaseUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $databases = $site->getDatabases();
            $domainName = $site->getDomainName();
            $siteEntity = $this->getSiteEntity($domainName);
            if (count($databases) && false === is_null($siteEntity)) {
                $databaseUserName = $request->get("databaseUserName");
                $databaseUserEntity = $this->databaseUserEntityManager->findOneByUserName($databaseUserName);
                if (false === is_null($databaseUserEntity)) {
                    $databaseUserEntity->setPassword('');
                    $form = $this->createDatabaseUserEditForm($site, $databaseUserEntity);
                    if (true === $request->isMethod("POST")) {
                        $form->handleRequest($request);
                        if (true === $form->isSubmitted()) {
                            $response = $this->handleDatabaseUserEditForm($request, $form, $site);
                            if (false === is_null($response)) {
                                return $response;
                            }
                        }
                    }
                    $response = $this->render("Frontend/Site/edit-database-user.html.twig", ["site" => $site, "databaseUser" => $databaseUserEntity, "form" => $form->createView(), "formErrors" => $this->formErrors]);
                }
            } else {
                $session = $request->getSession();
                $session->getFlashBag()->set("danger", $this->translator->trans("No database found; create a database first."));
                $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
            }
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createDatabaseUserEditForm(Site $site, DatabaseUserEntity $databaseUserEntity) : Form
    {
        $form = $this->createForm("App\\Form\\SiteDatabaseUserEditType", $databaseUserEntity, ["action" => $this->generateUrl("clp_site_database_user_edit", ["domainName" => $site->getDomainName(), "databaseUserName" => $databaseUserEntity->getUserName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleDatabaseUserEditForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $databaseUserEntity = $form->getData();
                    $encryptedPassword = Crypto::encrypt($databaseUserEntity->getPassword());
                    $databaseUserEntity->setPassword($encryptedPassword);
                    $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
                    $databaseManager = new DatabaseManager($activeDatabaseServerEntity);
                    $databaseManager->createUser($databaseUserEntity);
                    $this->databaseUserEntityManager->updateEntity($databaseUserEntity);
                    $databaseEntity = $databaseUserEntity->getDatabase();
                    $databaseName = $databaseEntity->getName();
                    $eventData = ["site" => $domainName, "databaseUserName" => $databaseUserEntity->getUserName(), "permissions" => $databaseUserEntity->getPermissions(), "database" => $databaseName];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_DATABASE_USER_EDIT, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Database User has been updated."));
                    $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_site_database_user_new", ["domainName" => $site->getDomainName()]));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteDatabaseUser(Request $request) : Response
    {
        $site = $this->getSite($request);
        $this->checkCsrfToken($request, "database-user-delete");
        if (false === is_null($site)) {
            $databaseUserName = $request->get("databaseUserName");
            $databaseUserEntity = $this->databaseUserEntityManager->findOneByUserName($databaseUserName);
            if (false === is_null($databaseUserEntity)) {
                try {
                    $databaseEntity = $databaseUserEntity->getDatabase();
                    if (false === is_null($databaseEntity) && $site->getDomainName() == $databaseEntity->getSite()->getDomainName()) {
                        $user = $this->getUser();
                        $domainName = $site->getDomainName();
                        $session = $request->getSession();
                        $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
                        $databaseManager = new DatabaseManager($activeDatabaseServerEntity);
                        $databaseManager->deleteUser($databaseUserEntity);
                        $this->databaseUserEntityManager->deleteEntity($databaseUserEntity);
                        $session->getFlashBag()->set("success", $this->translator->trans("Database User has been deleted."));
                        $eventData = ["site" => $domainName, "databaseUserName" => $databaseUserName];
                        EventQueue::addEvent(EventQueue::EVENT_SITE_DATABASE_USER_DELETE, $user, $eventData, $request);
                    }
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                    $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
                }
                $response = $this->redirect($this->generateUrl("clp_site_databases", ["domainName" => $site->getDomainName()]));
                return $response;
            }
        }
        $response = $this->redirect($this->generateUrl("clp_sites"));
        return $response;
    }
    public function varnishCache(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site) && true === $site->getVarnishCache()) {
            $varnishCacheSettings = $site->getVarnishCacheSettings();
            $varnishCacheSettingsData = ["isEnabled" => true === isset($varnishCacheSettings["enabled"]) && true === $varnishCacheSettings["enabled"] ? true : false, "server" => true === isset($varnishCacheSettings["server"]) ? $varnishCacheSettings["server"] : "127.0.0.1:6081", "cacheTagPrefix" => true === isset($varnishCacheSettings["cacheTagPrefix"]) ? $varnishCacheSettings["cacheTagPrefix"] : substr(md5(time()), 0, 4), "cacheLifetime" => true === isset($varnishCacheSettings["cacheLifetime"]) ? $varnishCacheSettings["cacheLifetime"] : "604800", "excludedParams" => true === isset($varnishCacheSettings["excludedParams"]) ? implode(",", $varnishCacheSettings["excludedParams"]) : "__SID,noCache,", "excludes" => true === isset($varnishCacheSettings["excludes"]) ? implode(PHP_EOL, $varnishCacheSettings["excludes"]) : ''];
            $settingsForm = $this->createVarnishCacheSettingsForm($site, $varnishCacheSettingsData);
            $purgeCacheForm = $this->createVarnishCachePurgeCacheForm($site);
            if (true === $request->isMethod("POST")) {
                $settingsForm->handleRequest($request);
                $purgeCacheForm->handleRequest($request);
                if (true === $settingsForm->isSubmitted()) {
                    $response = $this->handleVarnishCacheSettingsForm($request, $settingsForm, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
                if (true === $purgeCacheForm->isSubmitted()) {
                    $response = $this->handleVarnishCachePurgeCacheForm($request, $purgeCacheForm, $site);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/varnish-cache.html.twig", ["site" => $site, "settingsForm" => $settingsForm->createView(), "purgeCacheForm" => $purgeCacheForm->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createVarnishCacheSettingsForm(Site $site, array $data) : Form
    {
        $form = $this->createForm("App\\Form\\SiteVarnishCacheSettingsType", $data, ["action" => $this->generateUrl("clp_site_varnish_cache", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createVarnishCachePurgeCacheForm(Site $site) : Form
    {
        $form = $this->createForm("App\\Form\\SiteVarnishCachePurgeCacheType", ["site" => $site], ["action" => $this->generateUrl("clp_site_varnish_cache", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Purge Cache"]);
        return $form;
    }
    private function handleVarnishCacheSettingsForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $isEnabled = $form->get("isEnabled")->getData();
                    $server = $form->get("server")->getData();
                    $cacheLifetime = $form->get("cacheLifetime")->getData();
                    $cacheTagPrefix = $form->get("cacheTagPrefix")->getData();
                    $excludedParams = array_map("trim", array_filter(explode(",", trim($form->get("excludedParams")->getData()))));
                    $excludes = array_map("trim", array_filter(explode(PHP_EOL, trim($form->get("excludes")->getData()))));
                    $varnishCacheSettings = ["enabled" => $isEnabled, "server" => $server, "cacheTagPrefix" => $cacheTagPrefix, "cacheLifetime" => $cacheLifetime, "excludes" => $excludes, "excludedParams" => $excludedParams];
                    $site->setVarnishCacheSettings($varnishCacheSettings);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->writeVarnishCacheSettingsFile($varnishCacheSettings);
                    $siteUpdater->phpSettings();
                    $eventData = ["site" => $domainName, "varnishCacheEnabled" => $isEnabled, "server" => $server, "cacheTagPrefix" => $cacheTagPrefix, "cacheLifetime" => $cacheLifetime, "excludes" => $excludes, "excludedParams" => $excludedParams];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_VARNISH_CACHE_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Varnish Cache Settings have been saved."));
                    $response = $this->redirect($this->generateUrl("clp_site_varnish_cache", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleVarnishCachePurgeCacheForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $user = $this->getUser();
                    $varnishCacheSettings = $site->getVarnishCacheSettings();
                    $purgeValues = array_map("trim", array_filter(explode(",", trim($form->get("value")->getData()))));
                    if (!(false === empty($purgeValues) && true === isset($varnishCacheSettings["server"]) && false === empty($varnishCacheSettings["server"]))) {
                        throw new \Exception("Varnish Cache Settings cannot be empty.");
                    }
                    $varnishCacheClient = new VarnishCacheClient();
                    $varnishCacheClient->setServer($varnishCacheSettings["server"]);
                    foreach ($purgeValues as $purgeValue) {
                        $purgeValue = trim($purgeValue);
                        if (!(false === empty($purgeValue))) {
                            continue;
                        }
                        if (!(true === str_starts_with($purgeValue, "http"))) {
                            $varnishCacheClient->purgeTag($purgeValue);
                            continue;
                        }
                        $varnishCacheClient->purgeUrl($purgeValue);
                    }
                    $eventData = ["site" => $domainName, "values" => implode(",", $purgeValues)];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_VARNISH_CACHE_PURGE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Varnish Cache has been purged."));
                    $response = $this->redirect($this->generateUrl("clp_site_varnish_cache", ["domainName" => $site->getDomainName()]));
                } else {
                    $response = $this->redirect($this->generateUrl("clp_sites"));
                }
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function purgeVarnishCache(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $varnishCacheSettings = $site->getVarnishCacheSettings();
                if (true === isset($varnishCacheSettings["server"]) && true === isset($varnishCacheSettings["cacheTagPrefix"]) && false === empty($varnishCacheSettings["cacheTagPrefix"])) {
                    $cacheTagPrefix = $varnishCacheSettings["cacheTagPrefix"];
                    $varnishCacheClient = new VarnishCacheClient();
                    $varnishCacheClient->setServer($varnishCacheSettings["server"]);
                    $varnishCacheClient->purgeTag($cacheTagPrefix);
                    $varnishCacheClient->purgeHost($domainName);
                }
                $eventData = ["site" => $domainName, "purge" => "full"];
                EventQueue::addEvent(EventQueue::EVENT_SITE_VARNISH_CACHE_PURGE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Varnish Cache has been purged."));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
            $response = $this->redirect($this->generateUrl("clp_site_varnish_cache", ["domainName" => $site->getDomainName()]));
            return $response;
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function vhost(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            if (true === $request->isMethod("POST")) {
                $isCsrfTokenValid = $this->isCsrfTokenValid("vhost-update", (string) $request->get("token"));
                if (false === $isCsrfTokenValid) {
                    throw new InvalidCsrfTokenException("The CSRF token is invalid.");
                }
                $vhostTemplate = trim($request->get("vhost-template"));
                if (false === empty($vhostTemplate)) {
                    $domainName = $site->getDomainName();
                    $siteEntity = $this->getSiteEntity($domainName);
                    $oldVhostTemplate = $siteEntity->getVhostTemplate();
                    $site->setVhostTemplate($vhostTemplate);
                    $response = $this->handleVhostUpdate($request, $site, $siteEntity, $oldVhostTemplate);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/vhost.html.twig", ["site" => $site]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function handleVhostUpdate(Request $request, Site $site, SiteEntity $siteEntity, string $oldVhostTemplate)
    {
        try {
            $user = $this->getUser();
            $session = $request->getSession();
            if (true === str_contains($site->getVhostTemplate(), "disable_symlinks")) {
                throw new InvalidVhostException("disable_symlinks is not allowed.");
            }
            $domainName = $site->getDomainName();
            $vhostTemplate = $site->getVhostTemplate();
            $siteUpdater = $this->getSiteUpdater($site);
            $siteUpdater->updateNginxVhostWithRollback();
            $siteEntity->setVhostTemplate($vhostTemplate);
            $this->siteEntityManager->updateEntity($siteEntity);
            $session->getFlashBag()->set("success", $this->translator->trans("Vhost has been saved."));
            $eventData = ["site" => $domainName, "vhost" => $vhostTemplate, "oldVhost" => $oldVhostTemplate];
            EventQueue::addEvent(EventQueue::EVENT_SITE_VHOST_UPDATE, $user, $eventData, $request);
            $response = $this->redirect($this->generateUrl("clp_site_vhost", ["domainName" => $domainName]));
            return $response;
        } catch (InvalidVhostException $e) {
            $session->getFlashBag()->set("danger", $this->translator->trans("Vhost is not valid, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
    }
    public function settings(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            $domainSettingsData = ["domainName" => $site->getDomainName(), "rootDirectory" => $site->getRootDirectory()];
            $siteUserSettingsData = ["siteUser" => $site->getUser(), "sshKeys" => $site->getSshKeys()];
            $siteDeleteData = ["domainName" => $site->getDomainName()];
            $pageSpeedSettingsData = ["isEnabled" => $site->getPageSpeedEnabled(), "settings" => $site->getPageSpeedSettings()];
            $domainSettingsForm = $this->createDomainSettingsForm($site, $domainSettingsData);
            $siteUserSettingsForm = $this->createSiteUserSettingsForm($site, $siteUserSettingsData);
            $pageSpeedSettingsForm = $this->createPageSpeedSettingsForm($site, $pageSpeedSettingsData);
            $siteDeleteForm = $this->createSiteDeleteForm($site, $siteDeleteData);
            if (true === $request->isMethod("POST")) {
                $domainSettingsForm->handleRequest($request);
                $siteUserSettingsForm->handleRequest($request);
                $siteDeleteForm->handleRequest($request);
                $pageSpeedSettingsForm->handleRequest($request);
                $response = null;
                if (true === $domainSettingsForm->isSubmitted()) {
                    $response = $this->handleDomainSettingsForm($request, $domainSettingsForm, $site);
                }
                if (true === $siteUserSettingsForm->isSubmitted()) {
                    $response = $this->handleSiteUserSettingsForm($request, $siteUserSettingsForm, $site);
                }
                if (true === $pageSpeedSettingsForm->isSubmitted()) {
                    $response = $this->handlePageSpeedSettingsForm($request, $pageSpeedSettingsForm, $site);
                }
                if (true === $siteDeleteForm->isSubmitted()) {
                    $response = $this->handleSiteDeleteForm($request, $siteDeleteForm, $site);
                }
                if (false === is_null($response)) {
                    return $response;
                }
            }
            $parameters = ["site" => $site, "domainSettingsForm" => $domainSettingsForm->createView(), "siteUserSettingsForm" => $siteUserSettingsForm->createView(), "pageSpeedSettingsForm" => $pageSpeedSettingsForm->createView(), "siteDeleteForm" => $siteDeleteForm->createView()];
            if (SiteEntity::TYPE_NODEJS == $site->getType()) {
                $nodejsSettings = $site->getNodejsSettings();
                $nodejsSettingsForm = $this->createNodejsSettingsForm($site, $nodejsSettings);
                if (true === $request->isMethod("POST")) {
                    $currentNodeSettings = clone $nodejsSettings;
                    $nodejsSettingsForm->handleRequest($request);
                    if (true === $nodejsSettingsForm->isSubmitted()) {
                        $response = $this->handleNodejsSettingsForm($request, $nodejsSettingsForm, $currentNodeSettings, $site);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $parameters["nodejsSettingsForm"] = $nodejsSettingsForm->createView();
                unset($parameters["pageSpeedSettingsForm"]);
            }
            if (SiteEntity::TYPE_PHP == $site->getType()) {
                $phpSettings = $site->getPhpSettings();
                $phpSettingsForm = $this->createPhpSettingsForm($site, $phpSettings);
                if (true === $request->isMethod("POST")) {
                    $currentPhpSettings = clone $phpSettings;
                    $phpSettingsForm->handleRequest($request);
                    if (true === $phpSettingsForm->isSubmitted()) {
                        $response = $this->handlePhpSettingsForm($request, $phpSettingsForm, $currentPhpSettings, $site);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $parameters["phpSettingsForm"] = $phpSettingsForm->createView();
            }
            if (SiteEntity::TYPE_PYTHON == $site->getType()) {
                $pythonSettings = $site->getPythonSettings();
                $pythonSettingsForm = $this->createPythonSettingsForm($site, $pythonSettings);
                if (true === $request->isMethod("POST")) {
                    $pythonSettingsForm->handleRequest($request);
                    if (true === $pythonSettingsForm->isSubmitted()) {
                        $response = $this->handlePythonSettingsForm($request, $pythonSettingsForm, $site);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $parameters["pythonSettingsForm"] = $pythonSettingsForm->createView();
            }
            if (SiteEntity::TYPE_REVERSE_PROXY == $site->getType()) {
                $reverseProxySettingsForm = $this->createReverseProxySettingsForm($site);
                if (true === $request->isMethod("POST")) {
                    $reverseProxySettingsForm->handleRequest($request);
                    if (true === $reverseProxySettingsForm->isSubmitted()) {
                        $response = $this->handleReverseProxySettingsForm($request, $reverseProxySettingsForm, $site);
                        if (false === is_null($response)) {
                            return $response;
                        }
                    }
                }
                $parameters["reverseProxySettingsForm"] = $reverseProxySettingsForm->createView();
                unset($parameters["pageSpeedSettingsForm"]);
            }
            // Per-site resource quotas (CPU / memory / disk). Only the Entity\Site has these
            // accessors; the runtime Site\PhpSite wrapper doesn't. Skip the form when missing.
            if (method_exists($site, "getCpuQuota")) {
            $resourceQuotaData = [
                "cpuQuota" => $site->getCpuQuota(),
                "memoryQuota" => $site->getMemoryQuota(),
                "diskQuotaMb" => $site->getDiskQuotaMb(),
            ];
            $resourceQuotaForm = $this->createForm("App\\Form\\SiteResourceQuotaType", $resourceQuotaData, [
                "action" => $this->generateUrl("clp_site_resource_quota", ["domainName" => $site->getDomainName()]),
                "method" => "POST",
                "attr" => ["id" => "resource-quota-form"],
            ]);
            $resourceQuotaForm->add("submit", \Symfony\Component\Form\Extension\Core\Type\SubmitType::class, [
                "attr" => ["class" => "btn btn-blue btn-lg"],
                "label" => "Save",
            ]);
            $parameters["resourceQuotaForm"] = $resourceQuotaForm->createView();
            try {
                $probe = new \App\Site\ResourceQuota\DiskQuotaProbe();
                $parameters["diskQuotaHardSupported"] = $probe->isHardQuotaSupported();
            } catch (\Throwable $e) {
                $parameters["diskQuotaHardSupported"] = false;
            }
            if (true === isset($site)) {
                $parameters["lastDiskUsageMb"] = method_exists($site, "getLastDiskUsageMb") ? $site->getLastDiskUsageMb() : null;
                $parameters["lastDiskCheckAt"] = method_exists($site, "getLastDiskCheckAt") ? $site->getLastDiskCheckAt() : null;
            }
            }
            $parameters["formErrors"] = $this->formErrors;
            $response = $this->render("Frontend/Site/settings.html.twig", $parameters);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function updateResourceQuota(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (true === is_null($site)) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $session = $request->getSession();
        try {
            $submittedToken = (string) $request->request->get("_token");
            if (false === $this->isCsrfTokenValid("default", $submittedToken)) {
                throw new InvalidCsrfTokenException();
            }
            $payload = $request->request->get("clp_site_resource_quota", []);
            if (false === is_array($payload)) {
                $payload = [];
            }
            $cpu = $payload["cpuQuota"] ?? null;
            $mem = $payload["memoryQuota"] ?? null;
            $disk = $payload["diskQuotaMb"] ?? null;
            $cpuInt = $this->normalizeQuotaInput($cpu, 0, 1600);
            $memInt = $this->normalizeQuotaInput($mem, 0, 1048576);
            $diskInt = $this->normalizeQuotaInput($disk, 0, 10485760);
            $site->setCpuQuota($cpuInt);
            $site->setMemoryQuota($memInt);
            $site->setDiskQuotaMb($diskInt);
            $executor = $this->get("App\\System\\CommandExecutor");
            $logger = $this->logger ?? null;
            $probe = new \App\Site\ResourceQuota\DiskQuotaProbe($executor, $logger);
            $writer = new \App\Site\ResourceQuota\SystemdSliceWriter($executor, $logger);
            $writer->apply($site);
            if (true === $probe->isHardQuotaSupported()) {
                $applier = new \App\Site\ResourceQuota\XfsProjectQuotaApplier($executor, $logger);
                if (null === $diskInt || $diskInt <= 0) {
                    $applier->remove($site);
                    $site->setDiskQuotaProjectId(null);
                } else {
                    $applier->apply($site);
                }
            }
            $em = $this->getDoctrine()->getManager();
            $em->persist($site);
            $em->flush();
            $session->getFlashBag()->set("success", $this->translator->trans("Resource quotas have been updated."));
        } catch (InvalidCsrfTokenException $e) {
            $session->getFlashBag()->set("danger", $this->translator->trans("Invalid CSRF token."));
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->exception($e);
            }
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
    }
    private function normalizeQuotaInput($value, int $min, int $max) : ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (false === is_numeric($value)) {
            return null;
        }
        $intValue = (int) $value;
        if ($intValue <= 0) {
            return null;
        }
        if ($intValue < $min) {
            $intValue = $min;
        }
        if ($intValue > $max) {
            $intValue = $max;
        }
        return $intValue;
    }
    public function purgePageSpeedCache(Request $request) : Response
    {
        $site = $this->getSite($request);
        if (false === is_null($site)) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $siteUpdater = $this->getSiteUpdater($site);
                $siteUpdater->purgePageSpeedCache();
                $eventData = ["site" => $site->getDomainName()];
                EventQueue::addEvent(EventQueue::EVENT_SITE_PAGE_SPEED_CACHE_PURGE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Page Speed Cache has been purged."));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
            $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
            return $response;
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createDomainSettingsForm(Site $site, array $data) : Form
    {
        $form = $this->createForm("App\\Form\\SiteDomainSettingsType", $data, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createSiteUserSettingsForm(Site $site, array $data) : Form
    {
        $form = $this->createForm("App\\Form\\SiteUserSettingsType", $data, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createPageSpeedSettingsForm(Site $site, array $data) : Form
    {
        $form = $this->createForm("App\\Form\\SitePageSpeedSettingsType", $data, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createReverseProxySettingsForm(Site $site) : Form
    {
        $data = ["reverseProxyUrl" => $site->getReverseProxyUrl()];
        $form = $this->createForm("App\\Form\\SiteReverseProxySettingsType", $data, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => ["id" => "reverse-proxy-settings-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createNodejsSettingsForm(Site $site, NodejsSettings $nodejsSettings) : Form
    {
        $form = $this->createForm("App\\Form\\SiteNodejsSettingsType", $nodejsSettings, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => ["id" => "nodejs-settings-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createPhpSettingsForm(Site $site, PhpSettings $phpSettings) : Form
    {
        $form = $this->createForm("App\\Form\\SitePhpSettingsType", $phpSettings, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createPythonSettingsForm(Site $site, PythonSettings $pythonSettings) : Form
    {
        $form = $this->createForm("App\\Form\\SitePythonSettingsType", $pythonSettings, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function createSiteDeleteForm(Site $site, array $data) : Form
    {
        $form = $this->createForm("App\\Form\\SiteDeleteType", $data, ["action" => $this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg", "disabled" => "disabled"], "label" => "Delete Site"]);
        return $form;
    }
    private function handleDomainSettingsForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $rootDirectory = rtrim($form->get("rootDirectory")->getData(), "/");
                    $site->setRootDirectory($rootDirectory);
                    $siteEntity->setRootDirectory($rootDirectory);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->domainSettings();
                    $eventData = ["site" => $domainName, "rootDirectory" => $rootDirectory];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_ROOT_DIRECTORY_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Root Directory has been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleSiteUserSettingsForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $siteUser = $site->getUser();
                    $sshKeys = trim($form->get("sshKeys")->getData());
                    $password = trim($form->get("password")->getData());
                    $siteEntity->setSshKeys($sshKeys);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateUserSShKeys($siteUser, $sshKeys);
                    if (false === empty($password)) {
                        $siteUpdater->changeUserPassword($siteUser, $password);
                    }
                    $eventData = ["site" => $domainName, "sshKeys" => $sshKeys];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_USER_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Site User Settings have been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handlePageSpeedSettingsForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $isEnabled = $form->get("isEnabled")->getData();
                    $settings = trim($form->get("settings")->getData());
                    $site->setPageSpeedEnabled($isEnabled);
                    $site->setPageSpeedSettings($settings);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhostWithRollback();
                    $siteUpdater->purgePageSpeedCache();
                    $siteEntity->setPageSpeedEnabled($isEnabled);
                    $siteEntity->setPageSpeedSettings($settings);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "pageSpeedEnabled" => $isEnabled, "pageSpeedSettings" => $settings];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_PAGE_SPEED_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Page Speed Settings have been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (InvalidVhostException $e) {
                $session->getFlashBag()->set("danger", $this->translator->trans("PageSpeed Setting are not valid, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleSiteDeleteForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $siteDeleter = $this->getSiteDeleter($site);
                    $siteDeleter->delete();
                    $this->siteEntityManager->deleteEntity($siteEntity);
                    $eventData = ["site" => $domainName, "type" => $site->getType()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_DELETE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Site has been deleted."));
                }
                $response = $this->redirect($this->generateUrl("clp_sites"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleNodejsSettingsForm(Request $request, Form $form, NodejsSettings $currentNodejsSettings, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $nodejsSettings = $form->getData();
                    $nodejsVersion = $nodejsSettings->getNodejsVersion();
                    $hasNodejsVersionChanged = $currentNodejsSettings->getNodejsVersion() != $nodejsVersion;
                    $siteUpdater = $this->getSiteUpdater($site);
                    if (true === $hasNodejsVersionChanged) {
                        $siteUpdater->installNodejsVersion();
                    }
                    $siteUpdater->nodejsSettings();
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "nodejsVersion" => $nodejsVersion, "port" => $nodejsSettings->getPort()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_NODEJS_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Node.js Settings have been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handlePythonSettingsForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $pythonSettings = $form->getData();
                    $pythonVersion = $pythonSettings->getPythonVersion();
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->writePythonVersionFile();
                    $siteUpdater->pythonSettings();
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "pythonVersion" => $pythonVersion, "port" => $pythonSettings->getPort()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_PYTHON_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Python Settings have been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handleReverseProxySettingsForm(Request $request, Form $form, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $reverseProxyUrl = $form->get("reverseProxyUrl")->getData();
                    $site->setReverseProxyUrl($reverseProxyUrl);
                    $siteEntity->setReverseProxyUrl($reverseProxyUrl);
                    $siteUpdater = $this->getSiteUpdater($site);
                    $siteUpdater->updateNginxVhostWithRollback();
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "reverseProxyUrl" => $reverseProxyUrl];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_REVERSE_PROXY_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Reverse Proxy Settings have been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function handlePhpSettingsForm(Request $request, Form $form, PhpSettings $currentPhpSettings, Site $site)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $site->getDomainName();
                $siteEntity = $this->getSiteEntity($domainName);
                if (false === is_null($siteEntity)) {
                    $phpSettings = $form->getData();
                    $hasPhpVersionChanged = $currentPhpSettings->getPhpVersion() != $phpSettings->getPhpVersion();
                    $siteUpdater = $this->getSiteUpdater($site);
                    if (true === $hasPhpVersionChanged) {
                        $siteUpdater->changePhpVersion($currentPhpSettings->getPhpVersion(), $phpSettings->getPhpVersion());
                    }
                    $siteUpdater->phpSettings();
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $eventData = ["site" => $domainName, "phpVersion" => $phpSettings->getPhpVersion(), "memory_limit" => $phpSettings->getMemoryLimit(), "max_execution_time" => $phpSettings->getMaxExecutionTime(), "max_input_time" => $phpSettings->getMaxInputTime(), "max_input_vars" => $phpSettings->getMaxInputVars(), "post_max_size" => $phpSettings->getPostMaxSize(), "upload_max_filesize" => $phpSettings->getUploadMaxFileSize()];
                    EventQueue::addEvent(EventQueue::EVENT_SITE_PHP_SETTINGS_UPDATE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("PHP Settings have been saved."));
                }
                $response = $this->redirect($this->generateUrl("clp_site_settings", ["domainName" => $site->getDomainName()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function getSite(Request $request) : ?Site
    {
        $user = $this->getUser();
        $domainName = $request->get("domainName");
        $siteEntity = $this->getSiteEntity($domainName);
        $site = null;
        if (false === is_null($siteEntity)) {
            if (UserEntity::ROLE_USER == $user->getRole() && false === $user->hasSite($siteEntity)) {
                return null;
            }
            $siteType = $siteEntity->getType();
            switch ($siteType) {
                case SiteEntity::TYPE_NODEJS:
                    $site = new NodejsSite();
                    $site->setNodejsSettings($siteEntity->getNodejsSettings());
                    break;
                case SiteEntity::TYPE_STATIC:
                    $site = new StaticSite();
                    break;
                case SiteEntity::TYPE_PHP:
                    $site = new PhpSite();
                    $site->setPhpSettings($siteEntity->getPhpSettings());
                    $site->setVarnishCache($siteEntity->getVarnishCache());
                    break;
                case SiteEntity::TYPE_PYTHON:
                    $site = new PythonSite();
                    $site->setPythonSettings($siteEntity->getPythonSettings());
                    break;
                case SiteEntity::TYPE_REVERSE_PROXY:
                    $site = new ReverseProxySite();
                    $site->setReverseProxyUrl($siteEntity->getReverseProxyUrl());
                    break;
            }
            if (false === is_null($site)) {
                $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
                $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
                $subdomain = $resolvedDomainName->subDomain()->toString();
                $subdomain = false === empty($subdomain) ? $subdomain : null;
                $siteDatabases = $this->getSiteDatabases($siteEntity);
                $site->setUser($siteEntity->getUser());
                $site->setDomainName($siteEntity->getDomainName());
                $site->setRegistrableDomain($registrableDomain);
                $site->setSubdomain($subdomain);
                $site->setRootDirectory($siteEntity->getRootDirectory());
                $site->setBasicAuth($siteEntity->getBasicAuth());
                $site->setBlockedBots($siteEntity->getBlockedBots());
                $site->setBlockedIps($siteEntity->getBlockedIps());
                $site->setDatabases($siteDatabases);
                $site->setCertificate($siteEntity->getCertificate());
                $site->setCertificates($siteEntity->getCertificates());
                $site->setCronJobs($siteEntity->getCronJobs());
                $site->setFtpUsers($siteEntity->getFtpUsers());
                $site->setSshUsers($siteEntity->getSshUsers());
                $site->setSshKeys($siteEntity->getSshKeys());
                $site->setVhostTemplate($siteEntity->getVhostTemplate());
                $site->setAllowTrafficFromCloudflareOnly($siteEntity->allowTrafficFromCloudflareOnly());
                $site->setPageSpeedEnabled($siteEntity->getPageSpeedEnabled());
                $site->setPageSpeedSettings($siteEntity->getPageSpeedSettings());
            }
        }
        return $site;
    }
    private function getSiteDatabases(SiteEntity $siteEntity) : ?ArrayCollection
    {
        $siteDatabases = new ArrayCollection();
        $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
        $databaseEntities = $siteEntity->getDatabases();
        foreach ($databaseEntities as $databaseEntity) {
            $databaseServerEntity = $databaseEntity->getDatabaseServer();
            if (!($databaseServerEntity->getId() == $activeDatabaseServerEntity->getId())) {
                continue;
            }
            $siteDatabases->add($databaseEntity);
        }
        return $siteDatabases;
    }
    private function getSiteUpdater(Site $site) : ?SiteUpdater
    {
        $siteUpdater = null;
        $siteType = $site->getType();
        switch ($siteType) {
            case SiteEntity::TYPE_NODEJS:
                $siteUpdater = new NodejsSiteUpdater($site);
                break;
            case SiteEntity::TYPE_STATIC:
                $siteUpdater = new StaticSiteUpdater($site);
                break;
            case SiteEntity::TYPE_PHP:
                $siteUpdater = new PhpSiteUpdater($site);
                break;
            case SiteEntity::TYPE_PYTHON:
                $siteUpdater = new PythonSiteUpdater($site);
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $siteUpdater = new ReverseProxySiteUpdater($site);
                break;
        }
        return $siteUpdater;
    }
    protected function getSiteDeleter(Site $site) : ?SiteDeleter
    {
        $siteDeleter = null;
        $siteType = $site->getType();
        switch ($siteType) {
            case SiteEntity::TYPE_NODEJS:
                $siteDeleter = new NodejsSiteDeleter($site);
                break;
            case SiteEntity::TYPE_STATIC:
                $siteDeleter = new StaticSiteDeleter($site);
                break;
            case SiteEntity::TYPE_PHP:
                $siteDeleter = new PhpSiteDeleter($site);
                break;
            case SiteEntity::TYPE_PYTHON:
                $siteDeleter = new PythonSiteDeleter($site);
                break;
            case SiteEntity::TYPE_REVERSE_PROXY:
                $siteDeleter = new ReverseProxySiteDeleter($site);
                break;
        }
        return $siteDeleter;
    }
    private function getSiteEntity(string $domainName) : ?SiteEntity
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        return $siteEntity;
    }
}