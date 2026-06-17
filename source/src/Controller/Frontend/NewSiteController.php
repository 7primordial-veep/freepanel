<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Controller\Controller;
use App\Event\EventQueue;
use App\Service\Logger;
use App\Entity\Site as SiteEntity;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\NodejsSettings as NodejsSettingsEntity;
use App\Entity\PhpSettings as PhpSettingsEntity;
use App\Entity\PythonSettings as PythonSettingsEntity;
use App\Entity\Database as DatabaseEntity;
use App\Entity\DatabaseUser as DatabaseUserEntity;
use App\Entity\DatabaseServer as DatabaseServerEntity;
use App\Entity\User as UserEntity;
use App\Entity\Manager\ConfigManager;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\CertificateManager as CertificateEntityManager;
use App\Entity\Manager\DatabaseManager as DatabaseEntityManager;
use App\Entity\Manager\DatabaseUserManager as DatabaseUserEntityManager;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Entity\Manager\VhostTemplateManager as VhostTemplateEntityManager;
use App\Database\Connection as DatabaseConnection;
use App\Database\Manager as DatabaseManager;
use App\Site\Nginx\Vhost\StaticTemplate as StaticVhostTemplate;
use App\Site\Nginx\Vhost\PhpTemplate as PhpVhostTemplate;
use App\Site\Nginx\Vhost\PythonTemplate as PythonVhostTemplate;
use App\Site\Nginx\Vhost\ReverseProxyTemplate as ReverseProxyVhostTemplate;
use App\Site\Nginx\Vhost\Processor\RedirectDomain as RedirectDomainProcessor;
use App\Site\Nginx\Vhost\Processor\RedirectServerName as RedirectServerNameProcessor;
use App\Site\Nginx\Vhost\Processor\ServerName as ServerNameProcessor;
use App\Site\Parser\DomainName as DomainNameParser;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Creator\NodejsSite as NodejsSiteCreator;
use App\Site\Creator\StaticSite as StaticSiteCreator;
use App\Site\Creator\PhpSite as PhpSiteCreator;
use App\Site\Creator\PythonSite as PythonSiteCreator;
use App\Site\Creator\ReverseProxySite as ReverseProxySiteCreator;
use App\Site\Creator\DockerSite as DockerSiteCreator;
use App\Site\DockerSite;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\Util\Openssl;
use App\Site\NodejsSite;
use App\Site\StaticSite;
use App\Site\PhpSite;
use App\Site\PythonSite;
use App\Site\ReverseProxySite;
use App\Util\PasswordGenerator;
use App\Site\Application\WordPressInstaller;
use App\Service\Crypto;
class NewSiteController extends Controller
{
    private const WORDPRESS_VHOST_TEMPLATE_NAME = "WordPress";
    private const NODEJS_VHOST_TEMPLATE_NAME = "Nodejs";
    private const STATIC_SITE_VHOST_TEMPLATE_NAME = "Static";
    private const PYTHON_VHOST_TEMPLATE_NAME = "Python";
    private const REVERSE_PROXY_VHOST_TEMPLATE_NAME = "ReverseProxy";
    private const DOCKER_VHOST_TEMPLATE_NAME = "ReverseProxy";
    private const VARNISH_CACHE_SERVER = "127.0.0.1:6081";
    private const PASSWORD_LENGTH = 20;
    private ConfigManager $configManager;
    private SiteEntityManager $siteEntityManager;
    private CertificateEntityManager $certificateEntityManager;
    private DatabaseEntityManager $databaseEntityManager;
    private DatabaseUserEntityManager $databaseUserEntityManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    private DomainNameParser $domainNameParser;
    private VhostTemplateEntityManager $vhostTemplateEntityManager;
    private ValidatorInterface $validator;
    private array $wordPressDefaultConfigValues = ["FS_METHOD" => ["value" => "direct", "raw" => false], "WP_DEBUG_DISPLAY" => ["value" => "false", "raw" => true], "WP_DEBUG_LOG" => ["value" => "true", "raw" => true], "CONCATENATE_SCRIPTS" => ["value" => "false", "raw" => true], "AUTOSAVE_INTERVAL" => ["value" => 600, "raw" => true], "WP_POST_REVISIONS" => ["value" => 5, "raw" => true], "EMPTY_TRASH_DAYS" => ["value" => 21, "raw" => true]];
    private array $wordPressMultiSiteConfigValues = ["WP_ALLOW_MULTISITE" => ["value" => "true", "raw" => true], "MULTISITE" => ["value" => "true", "raw" => true], "SUBDOMAIN_INSTALL" => ["value" => "false", "raw" => true], "DOMAIN_CURRENT_SITE" => ["value" => '', "raw" => false], "PATH_CURRENT_SITE" => ["value" => "/", "raw" => false], "SITE_ID_CURRENT_SITE" => ["value" => 1, "raw" => true], "BLOG_ID_CURRENT_SITE" => ["value" => 1, "raw" => true]];
    public function __construct(ConfigManager $configManager, SiteEntityManager $siteEntityManager, CertificateEntityManager $certificateEntityManager, DatabaseEntityManager $databaseEntityManager, DatabaseUserEntityManager $databaseUserEntityManager, DatabaseServerEntityManager $databaseServerEntityManager, VhostTemplateEntityManager $vhostTemplateEntityManager, DomainNameParser $domainNameParser, ValidatorInterface $validator, TranslatorInterface $translator, Logger $logger)
    {
        $this->configManager = $configManager;
        $this->siteEntityManager = $siteEntityManager;
        $this->certificateEntityManager = $certificateEntityManager;
        $this->databaseEntityManager = $databaseEntityManager;
        $this->databaseUserEntityManager = $databaseUserEntityManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        $this->vhostTemplateEntityManager = $vhostTemplateEntityManager;
        $this->domainNameParser = $domainNameParser;
        $this->validator = $validator;
        parent::__construct($translator, $logger);
    }
    public function index(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $response = $this->render("Frontend/Site/New/index.html.twig", ["user" => $user]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    public function newPythonSite(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $pythonSettings = new PythonSettingsEntity();
            $form = $this->createPythonSiteForm($pythonSettings);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCreatePythonSiteForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/New/python.html.twig", ["user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createPythonSiteForm(PythonSettingsEntity $pythonSettings) : Form
    {
        $form = $this->createForm("App\\Form\\SiteNewPythonType", $pythonSettings, ["action" => $this->generateUrl("clp_site_python_new"), "method" => "POST", "attr" => ["id" => "new-python-site-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        return $form;
    }
    private function handleCreatePythonSiteForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $form->get("domainName")->getData();
                $pythonVersion = $form->get("pythonVersion")->getData();
                $port = $form->get("port")->getData();
                $siteUser = $form->get("siteUser")->getData();
                $siteUserPassword = $form->get("siteUserPassword")->getData();
                $vhostTemplateName = self::PYTHON_VHOST_TEMPLATE_NAME;
                $vhostTemplateEntity = $this->getVhostTemplateEntity($vhostTemplateName);
                if (!(false === is_null($vhostTemplateEntity))) {
                    throw new \Exception(sprintf("Vhost template \"%s\" not found.", $vhostTemplateName));
                }
                $user = $this->getUser();
                $this->createPythonSite($domainName, $pythonVersion, $port, $siteUser, $siteUserPassword);
                $eventData = ["site" => $domainName, "siteUser" => $siteUser, "pythonVersion" => $pythonVersion, "port" => $port];
                EventQueue::addEvent(EventQueue::EVENT_SITE_PYTHON_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Site has been created."));
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
    public function newDockerSite(Request $request) : Response
    {
        // ponytail: scaffold — minimum-viable Docker site create flow.
        $hasPermissions = $this->hasPermissions();
        if (true !== $hasPermissions) {
            return $this->redirect($this->generateUrl("clp_sites"));
        }
        $user = $this->getUser();
        $form = $this->createForm("App\\Form\\SiteNewDockerType", [], [
            "action" => $this->generateUrl("clp_site_docker_new"),
            "method" => "POST",
            "attr" => ["id" => "new-docker-site-form"],
        ]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        if ($request->isMethod("POST")) {
            $form->handleRequest($request);
            if ($form->isSubmitted()) {
                $session = $request->getSession();
                try {
                    if (!$form->isValid()) {
                        $this->formErrors = $this->getErrorMessages($form);
                    } else {
                        $domainName = $form->get("domainName")->getData();
                        $dockerImage = $form->get("dockerImage")->getData();
                        $dockerPort = (int) $form->get("dockerPort")->getData();
                        $dockerEnvRaw = (string) $form->get("dockerEnv")->getData();
                        $siteUser = $form->get("siteUser")->getData();
                        $siteUserPassword = $form->get("siteUserPassword")->getData();
                        // ponytail: scaffold — naive KEY=value,KEY=value parser
                        $env = [];
                        foreach (array_filter(array_map('trim', explode(',', $dockerEnvRaw))) as $pair) {
                            if (strpos($pair, '=') === false) {
                                continue;
                            }
                            [$k, $v] = explode('=', $pair, 2);
                            $env[trim($k)] = trim($v);
                        }
                        $vhostTemplateEntity = $this->getVhostTemplateEntity(self::DOCKER_VHOST_TEMPLATE_NAME);
                        if (is_null($vhostTemplateEntity)) {
                            throw new \Exception(sprintf("Vhost template \"%s\" not found.", self::DOCKER_VHOST_TEMPLATE_NAME));
                        }
                        $this->createDockerSite($domainName, $dockerImage, $dockerPort, $env, $siteUser, $siteUserPassword);
                        // ponytail: TODO add EVENT_SITE_DOCKER_CREATE to EventQueue
                        EventQueue::addEvent(EventQueue::EVENT_SITE_REVERSE_PROXY_CREATE, $this->getUser(), [
                            "site" => $domainName,
                            "siteUser" => $siteUser,
                            "dockerImage" => $dockerImage,
                            "dockerPort" => $dockerPort,
                        ], $request);
                        $session->getFlashBag()->set("success", $this->translator->trans("Docker site has been created."));
                        return $this->redirect($this->generateUrl("clp_sites"));
                    }
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                    $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
                }
            }
        }
        return $this->render("Frontend/Site/New/docker.html.twig", [
            "user" => $user,
            "form" => $form->createView(),
            "formErrors" => $this->formErrors,
        ]);
    }

    private function createDockerSite(string $domainName, string $dockerImage, int $dockerPort, array $env, string $siteUser, string $siteUserPassword) : SiteEntity
    {
        // ponytail: scaffold — clones the reverse-proxy create path, then
        // appends a `docker run -d` for the container. No retries, no pull.
        $vhostTemplateEntity = $this->getVhostTemplateEntity(self::DOCKER_VHOST_TEMPLATE_NAME);
        $containerName = sprintf('clp-%s', $siteUser);
        $reverseProxyUrl = sprintf('http://127.0.0.1:%d', $dockerPort);

        $siteEntity = new SiteEntity();
        $siteEntity->setType(SiteEntity::TYPE_DOCKER);
        $siteEntity->setDomainName($domainName);
        $siteEntity->setUser($siteUser);
        $siteEntity->setUserPassword($siteUserPassword);
        $siteEntity->setRootDirectory($domainName);
        $siteEntity->setVhostTemplate($vhostTemplateEntity->getTemplate());
        $siteEntity->setReverseProxyUrl($reverseProxyUrl);
        $siteEntity->setDockerImage($dockerImage);
        $siteEntity->setDockerPort($dockerPort);
        $siteEntity->setDockerEnv($env);
        $siteEntity->setDockerContainerName($containerName);

        $errors = $this->validator->validate($siteEntity);
        if (count($errors) > 0) {
            throw new \Exception((string) $errors);
        }

        $dockerSite = new DockerSite();
        $dockerSite->setUser($siteUser);
        $dockerSite->setUserPassword($siteUserPassword);
        $dockerSite->setDomainName($domainName);
        $dockerSite->setRootDirectory($domainName);
        $dockerSite->setVhostTemplate($vhostTemplateEntity->getTemplate());
        $dockerSite->setDockerImage($dockerImage);
        $dockerSite->setDockerPort($dockerPort);
        $dockerSite->setDockerEnv($env);
        $dockerSite->setDockerContainerName($containerName);

        $creator = new DockerSiteCreator($dockerSite);
        $creator->createUser();
        $creator->createRootDirectory();
        $creator->createLogrotateFile();
        $creator->resetPermissions();
        // Build nginx vhost via the existing reverse-proxy template.
        $vhostTemplate = new ReverseProxyVhostTemplate($dockerSite);
        $vhostTemplate->setContent($vhostTemplateEntity->getTemplate());
        $vhostTemplate->build();
        $vhostTemplate->removeEmptyPlaceholders();
        // Reuse Creator's vhost write step
        $dockerSite->setVhostTemplate($vhostTemplate->getContent());
        $creator->createNginxVhost();
        $creator->reloadNginxService();
        $creator->createContainer();

        $this->siteEntityManager->save($siteEntity);
        return $siteEntity;
    }

    public function newReverseProxy(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $form = $this->createReverseProxyForm();
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleReverseProxyForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/New/reverse-proxy.html.twig", ["user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createReverseProxyForm() : Form
    {
        $form = $this->createForm("App\\Form\\SiteNewReverseProxyType", [], ["action" => $this->generateUrl("clp_site_reverse_proxy_new"), "method" => "POST", "attr" => ["id" => "new-reverse-proxy-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        return $form;
    }
    private function handleReverseProxyForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $form->get("domainName")->getData();
                $siteUser = $form->get("siteUser")->getData();
                $siteUserPassword = $form->get("siteUserPassword")->getData();
                $reverseProxyUrl = $form->get("reverseProxyUrl")->getData();
                $vhostTemplateName = self::REVERSE_PROXY_VHOST_TEMPLATE_NAME;
                $vhostTemplateEntity = $this->getVhostTemplateEntity($vhostTemplateName);
                if (!(false === is_null($vhostTemplateEntity))) {
                    throw new \Exception(sprintf("Vhost template \"%s\" not found.", $vhostTemplateName));
                }
                $user = $this->getUser();
                $this->createReverseProxySite($domainName, $reverseProxyUrl, $siteUser, $siteUserPassword);
                $eventData = ["site" => $domainName, "siteUser" => $siteUser, "reverseProxyUrl" => $reverseProxyUrl];
                EventQueue::addEvent(EventQueue::EVENT_SITE_REVERSE_PROXY_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Reverse Proxy has been created."));
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
    public function newNodejsSite(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $nodejsSettings = new NodejsSettingsEntity();
            $form = $this->createNodejsSiteForm($nodejsSettings);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCreateNodejsSiteForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/New/nodejs.html.twig", ["user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createNodejsSiteForm(NodejsSettingsEntity $nodejsSettings) : Form
    {
        $form = $this->createForm("App\\Form\\SiteNewNodejsType", $nodejsSettings, ["action" => $this->generateUrl("clp_site_nodejs_new"), "method" => "POST", "attr" => ["id" => "new-nodejs-site-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        return $form;
    }
    private function handleCreateNodejsSiteForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $form->get("domainName")->getData();
                $nodejsVersion = $form->get("nodejsVersion")->getData();
                $port = $form->get("port")->getData();
                $siteUser = $form->get("siteUser")->getData();
                $siteUserPassword = $form->get("siteUserPassword")->getData();
                $vhostTemplateName = self::NODEJS_VHOST_TEMPLATE_NAME;
                $vhostTemplateEntity = $this->getVhostTemplateEntity($vhostTemplateName);
                if (!(false === is_null($vhostTemplateEntity))) {
                    throw new \Exception(sprintf("Vhost template \"%s\" not found.", $vhostTemplateName));
                }
                $user = $this->getUser();
                $this->createNodejsSite($domainName, $nodejsVersion, $port, $siteUser, $siteUserPassword);
                $eventData = ["site" => $domainName, "siteUser" => $siteUser, "nodejsVersion" => $nodejsVersion, "port" => $port];
                EventQueue::addEvent(EventQueue::EVENT_SITE_NODEJS_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Site has been created."));
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
    public function newStaticSite(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $form = $this->createStaticSiteForm();
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCreateStaticSiteForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/New/static.html.twig", ["user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createStaticSiteForm() : Form
    {
        $form = $this->createForm("App\\Form\\SiteNewStaticType", [], ["action" => $this->generateUrl("clp_site_static_new"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        return $form;
    }
    private function handleCreateStaticSiteForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $domainName = $form->get("domainName")->getData();
                $siteUser = $form->get("siteUser")->getData();
                $siteUserPassword = $form->get("siteUserPassword")->getData();
                $vhostTemplateName = self::STATIC_SITE_VHOST_TEMPLATE_NAME;
                $vhostTemplateEntity = $this->getVhostTemplateEntity($vhostTemplateName);
                if (!(false === is_null($vhostTemplateEntity))) {
                    throw new \Exception(sprintf("Vhost template \"%s\" not found.", $vhostTemplateName));
                }
                $user = $this->getUser();
                $this->createStaticSite($domainName, $siteUser, $siteUserPassword);
                $eventData = ["site" => $domainName, "siteUser" => $siteUser];
                EventQueue::addEvent(EventQueue::EVENT_SITE_STATIC_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Site has been created."));
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
    public function newPhpSite(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $form = $this->createPhpSiteForm();
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCreatePhpSiteForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/New/php.html.twig", ["user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createPhpSiteForm() : Form
    {
        $form = $this->createForm("App\\Form\\SiteNewPhpType", [], ["action" => $this->generateUrl("clp_site_php_new"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        return $form;
    }
    private function handleCreatePhpSiteForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $session = $request->getSession();
                $vhostTemplateName = $form->get("application")->getData();
                $domainName = $form->get("domainName")->getData();
                $phpVersion = $form->get("phpVersion")->getData();
                $siteUser = $form->get("siteUser")->getData();
                $siteUserPassword = $form->get("siteUserPassword")->getData();
                $vhostTemplateEntity = $this->getVhostTemplateEntity($vhostTemplateName);
                if (!(false === is_null($vhostTemplateEntity))) {
                    throw new \Exception(sprintf("Vhost template \"%s\" not found.", $vhostTemplateName));
                }
                $user = $this->getUser();
                $rootDirectory = $domainName;
                if (false === empty($vhostTemplateEntity->getRootDirectory())) {
                    $rootDirectory = sprintf("%s/%s", $rootDirectory, ltrim(rtrim($vhostTemplateEntity->getRootDirectory(), "/"), "/"));
                }
                $varnishCacheSettings = [];
                if (false === empty($vhostTemplateEntity->getVarnishCacheSettings())) {
                    $varnishCacheSettings = (array) json_decode($vhostTemplateEntity->getVarnishCacheSettings(), true);
                }
                $this->createPhpSite($domainName, $rootDirectory, $phpVersion, $vhostTemplateName, $siteUser, $siteUserPassword, $varnishCacheSettings);
                $eventData = ["site" => $domainName, "vhostTemplateName" => $vhostTemplateName, "phpVersion" => $phpVersion, "siteUser" => $siteUser];
                EventQueue::addEvent(EventQueue::EVENT_SITE_PHP_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Site has been created."));
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
    public function newWordPressSite(Request $request) : Response
    {
        $hasPermissions = $this->hasPermissions();
        if (true === $hasPermissions) {
            $user = $this->getUser();
            $form = $this->createWordPressSiteForm();
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleCreateWordPressSiteForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Frontend/Site/New/wordpress.html.twig", ["user" => $user, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function createWordPressSiteForm() : Form
    {
        $form = $this->createForm("App\\Form\\SiteNewWordPressType", [], ["action" => $this->generateUrl("clp_site_wordpress_new"), "method" => "POST", "attr" => ["id" => "new-wordpress-site-form"]]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-lg btn-blue"], "label" => "Create"]);
        return $form;
    }
    private function handleCreateWordPressSiteForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $form->get("domainName")->getData();
                $generatedName = $this->generateNameFromDomainName($domainName);
                $siteUser = $form->get("siteUser")->getData();
                $siteUserPassword = $form->get("siteUserPassword")->getData();
                $vhostTemplateEntity = $this->getVhostTemplateEntity(self::WORDPRESS_VHOST_TEMPLATE_NAME);
                $phpVersion = $vhostTemplateEntity->getPhpVersion();
                $varnishCacheSettings = [];
                if (false === empty($vhostTemplateEntity->getVarnishCacheSettings())) {
                    $varnishCacheSettings = (array) json_decode($vhostTemplateEntity->getVarnishCacheSettings(), true);
                }
                $siteEntity = $this->createPhpSite($domainName, $domainName, $phpVersion, self::WORDPRESS_VHOST_TEMPLATE_NAME, $siteUser, $siteUserPassword, $varnishCacheSettings);
                $siteEntity->setApplication($vhostTemplateEntity->getName());
                $instance = $request->attributes->get("instance");
                $siteCredentials = [$this->translator->trans("Site") => [$this->translator->trans("IP Address") => $instance->getIpv4PublicIp(), $this->translator->trans("Domain Name") => sprintf("https://%s", $domainName), $this->translator->trans("Site User") => $siteUser, $this->translator->trans("Password") => $siteUserPassword]];
                if (false === is_null($siteEntity)) {
                    $databaseName = $this->getUniqueDatabaseName($generatedName);
                    $databaseUserName = $this->getUniqueDatabaseUserName($generatedName);
                    $databaseUserPassword = PasswordGenerator::generate(self::PASSWORD_LENGTH);
                    $url = sprintf("https://%s", $siteEntity->getDomainName());
                    $title = $form->get("siteTitle")->getData();
                    $adminUserName = $form->get("adminUserName")->getData();
                    $adminPassword = $form->get("adminPassword")->getData();
                    $adminEmail = $form->get("adminEmail")->getData();
                    $isMultiSite = $form->get("multiSite")->getData();
                    $locale = $form->has("locale") ? (string) $form->get("locale")->getData() : "en_US";
                    $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
                    $databaseEntity = $this->databaseEntityManager->createEntity();
                    $databaseEntity->setDatabaseServer($activeDatabaseServerEntity);
                    $databaseEntity->setName($databaseName);
                    $databaseEntity->setSite($siteEntity);
                    $databaseUserEntity = $this->databaseUserEntityManager->createEntity();
                    $databaseUserEntity->setUserName($databaseUserName);
                    $databaseUserEntity->setPassword(Crypto::encrypt($databaseUserPassword));
                    $databaseUserEntity->setPermissions(DatabaseUserEntity::PERMISSIONS_READ_WRITE);
                    $databaseUserEntity->setDatabase($databaseEntity);
                    $databaseEntity->addUser($databaseUserEntity);
                    $siteEntity->addDatabase($databaseEntity);
                    $databaseManager = new DatabaseManager($activeDatabaseServerEntity);
                    $databaseManager->createDatabase($databaseEntity);
                    $databaseManager->createUser($databaseUserEntity);
                    $this->siteEntityManager->updateEntity($siteEntity);
                    $this->installWordPress($isMultiSite, $url, $title, $adminUserName, $adminPassword, $adminEmail, $locale, $siteEntity, $activeDatabaseServerEntity, $databaseEntity, $databaseUserEntity);
                    $siteCredentials[$this->translator->trans("Database")] = [$this->translator->trans("Host") => $activeDatabaseServerEntity->getHost(), $this->translator->trans("Port") => $activeDatabaseServerEntity->getPort(), $this->translator->trans("Database Name") => $databaseName, $this->translator->trans("Database User Name") => $databaseUserName, $this->translator->trans("Database User Password") => $databaseUserPassword];
                    $siteCredentials["WordPress"] = [$this->translator->trans("Admin E-Mail") => $adminEmail, $this->translator->trans("Admin User Name") => $adminUserName, $this->translator->trans("Admin Password") => $adminPassword, $this->translator->trans("Admin Url") => sprintf("https://%s/wp-admin/", rtrim($domainName, "/"))];
                }
                $session->set("siteCredentials", $siteCredentials);
                $eventData = ["site" => $domainName, "siteUser" => $siteUser];
                EventQueue::addEvent(EventQueue::EVENT_SITE_WORDPRESS_CREATE, $user, $eventData, $request);
                $response = $this->redirect($this->generateUrl("clp_site_wordpress_installed"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    private function installWordPress(bool $isMultiSite, string $url, string $title, string $adminUserName, string $adminPassword, string $adminEmail, string $locale, SiteEntity $siteEntity, DatabaseServerEntity $databaseServerEntity, DatabaseEntity $databaseEntity, DatabaseUserEntity $databaseUserEntity) : void
    {
        $databaseHost = sprintf("%s:%s", $databaseServerEntity->getHost(), $databaseServerEntity->getPort());
        $databaseName = $databaseEntity->getName();
        $databaseUserName = $databaseUserEntity->getUserName();
        $databaseUserPassword = Crypto::decrypt($databaseUserEntity->getPassword());
        $wordPressInstaller = new WordPressInstaller($siteEntity);
        $wordPressInstaller->downloadAndExtractLatestVersion();
        $wordPressInstaller->createConfig($databaseHost, $databaseName, $databaseUserName, $databaseUserPassword, $locale);
        $wordPressInstaller->installCore($isMultiSite, $url, $title, $adminUserName, $adminPassword, $adminEmail);
        foreach ($this->wordPressDefaultConfigValues as $key => $config) {
            $wordPressInstaller->setConfigValue($key, $config["value"], $config["raw"]);
        }
        if (true === $isMultiSite) {
            $this->wordPressMultiSiteConfigValues["DOMAIN_CURRENT_SITE"]["value"] = $siteEntity->getDomainName();
            foreach ($this->wordPressMultiSiteConfigValues as $key => $config) {
                $wordPressInstaller->setConfigValue($key, $config["value"], $config["raw"]);
            }
        }
        $wordPressInstaller->resetPermissions();
    }
    private function createPhpSite(string $domainName, string $rootDirectory, string $phpVersion, string $vhostTemplateName, string $siteUser, string $siteUserPassword, array $varnishCacheSettings = []) : SiteEntity
    {
        $varnishCache = false === empty($varnishCacheSettings) ? true : false;
        $rootDirectory = trim(rtrim(ltrim($rootDirectory, "/")), "/");
        $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
        $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
        $subdomain = $resolvedDomainName->subDomain()->toString();
        $subdomain = false === empty($subdomain) ? $subdomain : null;
        $vhostTemplateEntity = $this->getVhostTemplateEntity($vhostTemplateName);
        $vhostTemplate = $vhostTemplateEntity->getTemplate();
        $siteEntity = $this->siteEntityManager->createEntity();
        $siteEntity->setType(SiteEntity::TYPE_PHP);
        $siteEntity->setDomainName($domainName);
        $siteEntity->setUser($siteUser);
        $siteEntity->setUserPassword($siteUserPassword);
        $siteEntity->setRootDirectory($rootDirectory);
        if (false === empty($varnishCache)) {
            $siteEntity->setVarnishCache(true);
        }
        if (true === is_null($subdomain) || "www" == $subdomain) {
            if (true === isset($_ENV["APP_HTTP3"]) && "true" === $_ENV["APP_HTTP3"]) {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect-http3");
            } else {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect");
            }
            $redirectionVhostTemplate = file_get_contents($redirectionVhostTemplateFile);
            if (false === empty($redirectionVhostTemplate)) {
                $vhostTemplate = sprintf("%s%s", $redirectionVhostTemplate, $vhostTemplate);
            }
        }
        $siteEntity->setVhostTemplate($vhostTemplate);
        $phpSettingsEntity = new PhpSettingsEntity();
        $siteEntity->setPhpSettings($phpSettingsEntity);
        $phpSettingsEntity->setPhpVersion($phpVersion);
        $phpSettingsEntity->setSite($siteEntity);
        $siteConstraints = $this->validator->validate($siteEntity);
        $phpSettingsConstraints = $this->validator->validate($phpSettingsEntity);
        if (count($siteConstraints) || count($phpSettingsConstraints)) {
            $constraintViolationList = new ConstraintViolationList();
            $constraintViolationList->addAll($siteConstraints);
            $constraintViolationList->addAll($phpSettingsConstraints);
            $errorMessages = $this->getConstraintViolationListErrorMessages($constraintViolationList);
            throw new \Exception(implode("; ", $errorMessages));
        }
        $certificateEntity = $this->certificateEntityManager->createEntity();
        $certificateEntity->setSite($siteEntity);
        $rsaKeyGenerator = new RsaKeyGenerator();
        $privateKey = $rsaKeyGenerator->generatePrivateKey();
        $subjectAlternativeNames = [];
        if (true === is_null($subdomain)) {
            $subjectAlternativeNames[] = sprintf("www.%s", $domainName);
        }
        if (false === is_null($subdomain) && "www" == $subdomain) {
            $subjectAlternativeNames[] = $registrableDomain;
        }
        $distinguishedName = new DistinguishedName($domainName, $subjectAlternativeNames);
        $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
        $csr = $csrGenerator->generate();
        $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
        $certificateEntity->setDefaultCertificate(true);
        $certificateEntity->setType(CertificateEntity::TYPE_SELF_SIGNED);
        $certificateEntity->setCsr($csr);
        $certificateEntity->setPrivateKey($privateKey->getPEM());
        $certificateEntity->setCertificate($selfSignedCertificate);
        $siteEntity->setCertificate($certificateEntity);
        $phpSite = new PhpSite();
        $phpSite->setUser($siteEntity->getUser());
        $phpSite->setUserPassword($siteEntity->getUserPassword());
        $phpSite->setDomainName($domainName);
        $phpSite->setRegistrableDomain($registrableDomain);
        $phpSite->setSubdomain($subdomain);
        $phpSite->setRootDirectory($siteEntity->getRootDirectory());
        $phpSite->setCertificate($certificateEntity);
        $phpSite->setPhpSettings($phpSettingsEntity);
        $phpSite->setVhostTemplate($siteEntity->getVhostTemplate());
        $phpSiteCreator = new PhpSiteCreator($phpSite);
        $phpSiteCreator->createUser();
        $phpSiteCreator->createRootDirectory();
        $phpSiteCreator->createLogrotateFile();
        $phpSiteCreator->createIndexPhp();
        $phpSiteCreator->createPrivateKeyAndCertificate();
        $phpSiteCreator->createPhpFpmPool();
        $phpSiteCreator->reloadPhpFpmService();
        if (true === $varnishCache) {
            $phpSite->setVarnishCache(true);
            $defaultVarnishCacheSettings = ["enabled" => false, "server" => self::VARNISH_CACHE_SERVER, "cacheTagPrefix" => substr(md5(time()), 0, 4)];
            $varnishCacheSettings = array_merge($defaultVarnishCacheSettings, $varnishCacheSettings);
            $phpSiteCreator->createVarnishCacheStructure($varnishCacheSettings);
            $phpSite->setVarnishCacheSettings($varnishCacheSettings);
        }
        $phpSiteCreator->createNginxVhost();
        $phpSiteCreator->reloadNginxService();
        $phpSiteCreator->resetPermissions();
        $vhostTemplate = new PhpVhostTemplate($phpSite);
        $vhostTemplate->setContent($siteEntity->getVhostTemplate());
        $vhostTemplate->resetProcessors();
        $vhostTemplate->addProcessor(new ServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectDomainProcessor());
        $vhostTemplate->build();
        $siteEntity->setVhostTemplate($vhostTemplate->getContent());
        $siteEntity->setApplication($vhostTemplateEntity->getName());
        $this->siteEntityManager->updateEntity($siteEntity);
        return $siteEntity;
    }
    private function createNodejsSite(string $domainName, string $nodejsVersion, string $port, string $siteUser, string $siteUserPassword) : SiteEntity
    {
        $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
        $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
        $subdomain = $resolvedDomainName->subDomain()->toString();
        $subdomain = false === empty($subdomain) ? $subdomain : null;
        $vhostTemplateEntity = $this->getVhostTemplateEntity(self::NODEJS_VHOST_TEMPLATE_NAME);
        $vhostTemplate = $vhostTemplateEntity->getTemplate();
        $siteEntity = $this->siteEntityManager->createEntity();
        $siteEntity->setType(SiteEntity::TYPE_NODEJS);
        $siteEntity->setDomainName($domainName);
        $siteEntity->setUser($siteUser);
        $siteEntity->setUserPassword($siteUserPassword);
        $siteEntity->setRootDirectory($domainName);
        if (true === is_null($subdomain) || "www" == $subdomain) {
            if (true === isset($_ENV["APP_HTTP3"]) && "true" === $_ENV["APP_HTTP3"]) {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect-http3");
            } else {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect");
            }
            $redirectionVhostTemplate = file_get_contents($redirectionVhostTemplateFile);
            if (false === empty($redirectionVhostTemplate)) {
                $vhostTemplate = sprintf("%s%s", $redirectionVhostTemplate, $vhostTemplate);
            }
        }
        $siteEntity->setVhostTemplate($vhostTemplate);
        $nodejsSettingsEntity = new NodejsSettingsEntity();
        $siteEntity->setNodejsSettings($nodejsSettingsEntity);
        $nodejsSettingsEntity->setNodejsVersion($nodejsVersion);
        $nodejsSettingsEntity->setPort($port);
        $nodejsSettingsEntity->setSite($siteEntity);
        $siteConstraints = $this->validator->validate($siteEntity);
        $nodejsSettingsConstraints = $this->validator->validate($nodejsSettingsEntity);
        if (count($siteConstraints) || count($nodejsSettingsConstraints)) {
            $constraintViolationList = new ConstraintViolationList();
            $constraintViolationList->addAll($siteConstraints);
            $constraintViolationList->addAll($nodejsSettingsConstraints);
            $errorMessages = $this->getConstraintViolationListErrorMessages($constraintViolationList);
            throw new \Exception(implode("; ", $errorMessages));
        }
        $certificateEntity = $this->certificateEntityManager->createEntity();
        $certificateEntity->setSite($siteEntity);
        $rsaKeyGenerator = new RsaKeyGenerator();
        $privateKey = $rsaKeyGenerator->generatePrivateKey();
        $subjectAlternativeNames = [];
        if (true === is_null($subdomain)) {
            $subjectAlternativeNames[] = sprintf("www.%s", $domainName);
        }
        if (false === is_null($subdomain) && "www" == $subdomain) {
            $subjectAlternativeNames[] = $registrableDomain;
        }
        $distinguishedName = new DistinguishedName($domainName, $subjectAlternativeNames);
        $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
        $csr = $csrGenerator->generate();
        $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
        $certificateEntity->setDefaultCertificate(true);
        $certificateEntity->setType(CertificateEntity::TYPE_SELF_SIGNED);
        $certificateEntity->setCsr($csr);
        $certificateEntity->setPrivateKey($privateKey->getPEM());
        $certificateEntity->setCertificate($selfSignedCertificate);
        $siteEntity->setCertificate($certificateEntity);
        $nodejsSite = new NodejsSite();
        $nodejsSite->setUser($siteEntity->getUser());
        $nodejsSite->setUserPassword($siteEntity->getUserPassword());
        $nodejsSite->setDomainName($domainName);
        $nodejsSite->setRegistrableDomain($registrableDomain);
        $nodejsSite->setSubdomain($subdomain);
        $nodejsSite->setRootDirectory($siteEntity->getRootDirectory());
        $nodejsSite->setCertificate($certificateEntity);
        $nodejsSite->setNodejsSettings($nodejsSettingsEntity);
        $nodejsSite->setVhostTemplate($siteEntity->getVhostTemplate());
        $nodejsSiteCreator = new NodejsSiteCreator($nodejsSite);
        $nodejsSiteCreator->createUser();
        $nodejsSiteCreator->createRootDirectory();
        $nodejsSiteCreator->createLogrotateFile();
        $nodejsSiteCreator->createNvmDirectory();
        $nodejsSiteCreator->installNodejs();
        $nodejsSiteCreator->createPrivateKeyAndCertificate();
        $nodejsSiteCreator->createNginxVhost();
        $nodejsSiteCreator->reloadNginxService();
        $nodejsSiteCreator->resetPermissions();
        $vhostTemplate = new StaticVhostTemplate($nodejsSite);
        $vhostTemplate->setContent($siteEntity->getVhostTemplate());
        $vhostTemplate->resetProcessors();
        $vhostTemplate->addProcessor(new ServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectDomainProcessor());
        $vhostTemplate->build();
        $siteEntity->setVhostTemplate($vhostTemplate->getContent());
        $siteEntity->setApplication($vhostTemplateEntity->getName());
        $this->siteEntityManager->updateEntity($siteEntity);
        return $siteEntity;
    }
    private function createPythonSite(string $domainName, string $pythonVersion, string $port, string $siteUser, string $siteUserPassword) : SiteEntity
    {
        $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
        $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
        $subdomain = $resolvedDomainName->subDomain()->toString();
        $subdomain = false === empty($subdomain) ? $subdomain : null;
        $vhostTemplateEntity = $this->getVhostTemplateEntity(self::PYTHON_VHOST_TEMPLATE_NAME);
        $vhostTemplate = $vhostTemplateEntity->getTemplate();
        $siteEntity = $this->siteEntityManager->createEntity();
        $siteEntity->setType(SiteEntity::TYPE_PYTHON);
        $siteEntity->setDomainName($domainName);
        $siteEntity->setUser($siteUser);
        $siteEntity->setUserPassword($siteUserPassword);
        $siteEntity->setRootDirectory($domainName);
        if (true === is_null($subdomain) || "www" == $subdomain) {
            if (true === isset($_ENV["APP_HTTP3"]) && "true" === $_ENV["APP_HTTP3"]) {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect-http3");
            } else {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect");
            }
            $redirectionVhostTemplate = file_get_contents($redirectionVhostTemplateFile);
            if (false === empty($redirectionVhostTemplate)) {
                $vhostTemplate = sprintf("%s%s", $redirectionVhostTemplate, $vhostTemplate);
            }
        }
        $siteEntity->setVhostTemplate($vhostTemplate);
        $pythonSettingsEntity = new PythonSettingsEntity();
        $siteEntity->setPythonSettings($pythonSettingsEntity);
        $pythonSettingsEntity->setPythonVersion($pythonVersion);
        $pythonSettingsEntity->setPort($port);
        $pythonSettingsEntity->setSite($siteEntity);
        $siteConstraints = $this->validator->validate($siteEntity);
        $pythonSettingsConstraints = $this->validator->validate($pythonSettingsEntity);
        if (count($siteConstraints) || count($pythonSettingsConstraints)) {
            $constraintViolationList = new ConstraintViolationList();
            $constraintViolationList->addAll($siteConstraints);
            $constraintViolationList->addAll($pythonSettingsConstraints);
            $errorMessages = $this->getConstraintViolationListErrorMessages($constraintViolationList);
            throw new \Exception(implode("; ", $errorMessages));
        }
        $certificateEntity = $this->certificateEntityManager->createEntity();
        $certificateEntity->setSite($siteEntity);
        $rsaKeyGenerator = new RsaKeyGenerator();
        $privateKey = $rsaKeyGenerator->generatePrivateKey();
        $subjectAlternativeNames = [];
        if (true === is_null($subdomain)) {
            $subjectAlternativeNames[] = sprintf("www.%s", $domainName);
        }
        if (false === is_null($subdomain) && "www" == $subdomain) {
            $subjectAlternativeNames[] = $registrableDomain;
        }
        $distinguishedName = new DistinguishedName($domainName, $subjectAlternativeNames);
        $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
        $csr = $csrGenerator->generate();
        $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
        $certificateEntity->setDefaultCertificate(true);
        $certificateEntity->setType(CertificateEntity::TYPE_SELF_SIGNED);
        $certificateEntity->setCsr($csr);
        $certificateEntity->setPrivateKey($privateKey->getPEM());
        $certificateEntity->setCertificate($selfSignedCertificate);
        $siteEntity->setCertificate($certificateEntity);
        $pythonSite = new PythonSite();
        $pythonSite->setUser($siteEntity->getUser());
        $pythonSite->setUserPassword($siteEntity->getUserPassword());
        $pythonSite->setDomainName($domainName);
        $pythonSite->setRegistrableDomain($registrableDomain);
        $pythonSite->setSubdomain($subdomain);
        $pythonSite->setRootDirectory($siteEntity->getRootDirectory());
        $pythonSite->setCertificate($certificateEntity);
        $pythonSite->setPythonSettings($pythonSettingsEntity);
        $pythonSite->setVhostTemplate($siteEntity->getVhostTemplate());
        $pythonSiteCreator = new PythonSiteCreator($pythonSite);
        $pythonSiteCreator->createUser();
        $pythonSiteCreator->createRootDirectory();
        $pythonSiteCreator->createLogrotateFile();
        $pythonSiteCreator->writePythonVersionFile();
        $pythonSiteCreator->createPrivateKeyAndCertificate();
        $pythonSiteCreator->createNginxVhost();
        $pythonSiteCreator->reloadNginxService();
        $pythonSiteCreator->resetPermissions();
        $vhostTemplate = new PythonVhostTemplate($pythonSite);
        $vhostTemplate->setContent($siteEntity->getVhostTemplate());
        $vhostTemplate->resetProcessors();
        $vhostTemplate->addProcessor(new ServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectDomainProcessor());
        $vhostTemplate->build();
        $siteEntity->setVhostTemplate($vhostTemplate->getContent());
        $this->siteEntityManager->updateEntity($siteEntity);
        $siteEntity->setApplication($vhostTemplateEntity->getName());
        return $siteEntity;
    }
    private function createStaticSite(string $domainName, string $siteUser, string $siteUserPassword) : SiteEntity
    {
        $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
        $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
        $subdomain = $resolvedDomainName->subDomain()->toString();
        $subdomain = false === empty($subdomain) ? $subdomain : null;
        $vhostTemplateEntity = $this->getVhostTemplateEntity(self::STATIC_SITE_VHOST_TEMPLATE_NAME);
        $vhostTemplate = $vhostTemplateEntity->getTemplate();
        $siteEntity = $this->siteEntityManager->createEntity();
        $siteEntity->setType(SiteEntity::TYPE_STATIC);
        $siteEntity->setDomainName($domainName);
        $siteEntity->setUser($siteUser);
        $siteEntity->setUserPassword($siteUserPassword);
        $siteEntity->setRootDirectory($domainName);
        if (true === is_null($subdomain) || "www" == $subdomain) {
            if (true === isset($_ENV["APP_HTTP3"]) && "true" === $_ENV["APP_HTTP3"]) {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect-http3");
            } else {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect");
            }
            $redirectionVhostTemplate = file_get_contents($redirectionVhostTemplateFile);
            if (false === empty($redirectionVhostTemplate)) {
                $vhostTemplate = sprintf("%s%s", $redirectionVhostTemplate, $vhostTemplate);
            }
        }
        $siteEntity->setVhostTemplate($vhostTemplate);
        $siteConstraints = $this->validator->validate($siteEntity);
        if (count($siteConstraints)) {
            $constraintViolationList = new ConstraintViolationList();
            $constraintViolationList->addAll($siteConstraints);
            $errorMessages = $this->getConstraintViolationListErrorMessages($constraintViolationList);
            throw new \Exception(implode("; ", $errorMessages));
        }
        $certificateEntity = $this->certificateEntityManager->createEntity();
        $certificateEntity->setSite($siteEntity);
        $rsaKeyGenerator = new RsaKeyGenerator();
        $privateKey = $rsaKeyGenerator->generatePrivateKey();
        $subjectAlternativeNames = [];
        if (true === is_null($subdomain)) {
            $subjectAlternativeNames[] = sprintf("www.%s", $domainName);
        }
        if (false === is_null($subdomain) && "www" == $subdomain) {
            $subjectAlternativeNames[] = $registrableDomain;
        }
        $distinguishedName = new DistinguishedName($domainName, $subjectAlternativeNames);
        $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
        $csr = $csrGenerator->generate();
        $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
        $certificateEntity->setDefaultCertificate(true);
        $certificateEntity->setType(CertificateEntity::TYPE_SELF_SIGNED);
        $certificateEntity->setCsr($csr);
        $certificateEntity->setPrivateKey($privateKey->getPEM());
        $certificateEntity->setCertificate($selfSignedCertificate);
        $siteEntity->setCertificate($certificateEntity);
        $staticSite = new StaticSite();
        $staticSite->setUser($siteEntity->getUser());
        $staticSite->setUserPassword($siteEntity->getUserPassword());
        $staticSite->setDomainName($domainName);
        $staticSite->setRegistrableDomain($registrableDomain);
        $staticSite->setSubdomain($subdomain);
        $staticSite->setRootDirectory($siteEntity->getRootDirectory());
        $staticSite->setCertificate($certificateEntity);
        $staticSite->setVhostTemplate($siteEntity->getVhostTemplate());
        $staticSiteCreator = new StaticSiteCreator($staticSite);
        $staticSiteCreator->createUser();
        $staticSiteCreator->createRootDirectory();
        $staticSiteCreator->createLogrotateFile();
        $staticSiteCreator->createIndexHtml();
        $staticSiteCreator->createPrivateKeyAndCertificate();
        $staticSiteCreator->createNginxVhost();
        $staticSiteCreator->reloadNginxService();
        $staticSiteCreator->resetPermissions();
        $vhostTemplate = new StaticVhostTemplate($staticSite);
        $vhostTemplate->setContent($siteEntity->getVhostTemplate());
        $vhostTemplate->resetProcessors();
        $vhostTemplate->addProcessor(new ServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectDomainProcessor());
        $vhostTemplate->build();
        $siteEntity->setVhostTemplate($vhostTemplate->getContent());
        $siteEntity->setApplication($vhostTemplateEntity->getName());
        $this->siteEntityManager->updateEntity($siteEntity);
        return $siteEntity;
    }
    private function createReverseProxySite(string $domainName, string $reverseProxyUrl, string $siteUser, string $siteUserPassword) : SiteEntity
    {
        $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
        $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
        $subdomain = $resolvedDomainName->subDomain()->toString();
        $subdomain = false === empty($subdomain) ? $subdomain : null;
        $vhostTemplateEntity = $this->getVhostTemplateEntity(self::REVERSE_PROXY_VHOST_TEMPLATE_NAME);
        $vhostTemplate = $vhostTemplateEntity->getTemplate();
        $siteEntity = $this->siteEntityManager->createEntity();
        $siteEntity->setType(SiteEntity::TYPE_REVERSE_PROXY);
        $siteEntity->setDomainName($domainName);
        $siteEntity->setUser($siteUser);
        $siteEntity->setUserPassword($siteUserPassword);
        $siteEntity->setRootDirectory($domainName);
        $siteEntity->setReverseProxyUrl($reverseProxyUrl);
        if (true === is_null($subdomain) || "www" == $subdomain) {
            if (true === isset($_ENV["APP_HTTP3"]) && "true" === $_ENV["APP_HTTP3"]) {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect-http3");
            } else {
                $redirectionVhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/redirect");
            }
            $redirectionVhostTemplate = file_get_contents($redirectionVhostTemplateFile);
            if (false === empty($redirectionVhostTemplate)) {
                $vhostTemplate = sprintf("%s%s", $redirectionVhostTemplate, $vhostTemplate);
            }
        }
        $siteEntity->setVhostTemplate($vhostTemplate);
        $siteConstraints = $this->validator->validate($siteEntity);
        if (count($siteConstraints)) {
            $constraintViolationList = new ConstraintViolationList();
            $constraintViolationList->addAll($siteConstraints);
            $errorMessages = $this->getConstraintViolationListErrorMessages($constraintViolationList);
            throw new \Exception(implode("; ", $errorMessages));
        }
        $certificateEntity = $this->certificateEntityManager->createEntity();
        $certificateEntity->setSite($siteEntity);
        $rsaKeyGenerator = new RsaKeyGenerator();
        $privateKey = $rsaKeyGenerator->generatePrivateKey();
        $subjectAlternativeNames = [];
        if (true === is_null($subdomain)) {
            $subjectAlternativeNames[] = sprintf("www.%s", $domainName);
        }
        if (false === is_null($subdomain) && "www" == $subdomain) {
            $subjectAlternativeNames[] = $registrableDomain;
        }
        $distinguishedName = new DistinguishedName($domainName, $subjectAlternativeNames);
        $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
        $csr = $csrGenerator->generate();
        $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
        $certificateEntity->setDefaultCertificate(true);
        $certificateEntity->setType(CertificateEntity::TYPE_SELF_SIGNED);
        $certificateEntity->setCsr($csr);
        $certificateEntity->setPrivateKey($privateKey->getPEM());
        $certificateEntity->setCertificate($selfSignedCertificate);
        $siteEntity->setCertificate($certificateEntity);
        $reverseProxySite = new ReverseProxySite();
        $reverseProxySite->setUser($siteEntity->getUser());
        $reverseProxySite->setUserPassword($siteEntity->getUserPassword());
        $reverseProxySite->setDomainName($domainName);
        $reverseProxySite->setRegistrableDomain($registrableDomain);
        $reverseProxySite->setSubdomain($subdomain);
        $reverseProxySite->setRootDirectory($siteEntity->getRootDirectory());
        $reverseProxySite->setCertificate($certificateEntity);
        $reverseProxySite->setReverseProxyUrl($reverseProxyUrl);
        $reverseProxySite->setVhostTemplate($siteEntity->getVhostTemplate());
        $reversProxySiteCreator = new ReverseProxySiteCreator($reverseProxySite);
        $reversProxySiteCreator->createUser();
        $reversProxySiteCreator->createRootDirectory();
        $reversProxySiteCreator->createLogrotateFile();
        $reversProxySiteCreator->createPrivateKeyAndCertificate();
        $reversProxySiteCreator->createNginxVhost();
        $reversProxySiteCreator->reloadNginxService();
        $reversProxySiteCreator->resetPermissions();
        $vhostTemplate = new ReverseProxyVhostTemplate($reverseProxySite);
        $vhostTemplate->setContent($siteEntity->getVhostTemplate());
        $vhostTemplate->resetProcessors();
        $vhostTemplate->addProcessor(new ServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectServerNameProcessor());
        $vhostTemplate->addProcessor(new RedirectDomainProcessor());
        $vhostTemplate->build();
        $siteEntity->setVhostTemplate($vhostTemplate->getContent());
        $siteEntity->setApplication($vhostTemplateEntity->getName());
        $this->siteEntityManager->updateEntity($siteEntity);
        return $siteEntity;
    }
    public function installedWordPressSite(Request $request) : Response
    {
        $session = $request->getSession();
        $siteCredentials = $session->get("siteCredentials");
        if (false === empty($siteCredentials)) {
            $siteCredentials = $this->renderSiteCredentials($siteCredentials);
            $response = $this->render("Frontend/Site/New/wordpress-installed.html.twig", ["siteCredentials" => $siteCredentials]);
            return $response;
        } else {
            $response = $this->redirect($this->generateUrl("clp_sites"));
        }
        return $response;
    }
    private function renderSiteCredentials(array $siteCredentials) : string
    {
        $renderedCredentials = '';
        foreach ($siteCredentials as $section => $sectionData) {
            $renderedCredentials .= $section . PHP_EOL;
            $renderedCredentials .= "------------------------------------------------------" . PHP_EOL;
            foreach ($sectionData as $key => $value) {
                $renderedCredentials .= sprintf("%s: %s", $key, $value) . PHP_EOL;
            }
            $renderedCredentials .= PHP_EOL;
        }
        return $renderedCredentials;
    }
    private function getVhostTemplateEntity(string $name) : mixed
    {
        $vhostTemplateEntity = $this->vhostTemplateEntityManager->findOneByName($name);
        if (true === is_null($vhostTemplateEntity)) {
            throw new \Exception($this->translator->trans("Vhost Template %vhostTemplate% does not exist.", ["%vhostTemplate%" => $name]));
        }
        return $vhostTemplateEntity;
    }
    private function getConstraintViolationListErrorMessages(ConstraintViolationList $constraintViolationList) : array
    {
        $errorMessages = [];
        foreach ($constraintViolationList as $constraint) {
            $errorMessages[] = sprintf("%s: %s", $constraint->getPropertyPath(), $constraint->getMessage());
        }
        return $errorMessages;
    }
    private function generateNameFromDomainName(string $domainName) : string
    {
        $resolvedDomainName = $this->domainNameParser->resolveDomainName($domainName);
        $registrableDomain = $resolvedDomainName->registrableDomain()->toString();
        $subdomain = $resolvedDomainName->subDomain()->toString();
        $explodedRegistrableDomain = explode(".", $registrableDomain);
        $name = $explodedRegistrableDomain[0] ?? '';
        if (false === empty($subdomain) && "www" != $subdomain) {
        $explodedSubdomain = explode(".", $subdomain);
        $name = sprintf("%s-%s", $name, implode("-", $explodedSubdomain));
        }
        return $name;
    }
    private function getUniqueDatabaseName(string $databaseName) : string
    {
        $uniqueDatabaseName = $databaseName;
        if (strlen($uniqueDatabaseName) <= 3) {
            $uniqueDatabaseName = sprintf("%s-db", $uniqueDatabaseName);
        }
        $i = 0;
        $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
        $databaseConnection = new DatabaseConnection($activeDatabaseServerEntity);
        $databases = $databaseConnection->getDatabases();
        do {
            if (0 < $i) {
                $uniqueDatabaseName = sprintf("%s-%s", $databaseName, $i);
            }
            $databaseExists = in_array($uniqueDatabaseName, $databases);
            $i++;
        } while (true === $databaseExists);
        return $uniqueDatabaseName;
    }
    private function getUniqueDatabaseUserName(string $databaseUserName) : string
    {
        $uniqueDatabaseUserName = $databaseUserName;
        if (strlen($uniqueDatabaseUserName) <= 3) {
            $uniqueDatabaseUserName = sprintf("%s-db", $uniqueDatabaseUserName);
        }
        $i = 0;
        do {
            if (0 < $i) {
                $uniqueDatabaseUserName = sprintf("%s-%s", $databaseUserName, $i);
            }
            $databaseUserEntity = $this->databaseUserEntityManager->findOneByUserName($uniqueDatabaseUserName);
            $i++;
        } while (false === is_null($databaseUserEntity));
        $uniqueDatabaseUserName = mb_substr($uniqueDatabaseUserName, 0, 32);
        return $uniqueDatabaseUserName;
    }
    private function hasPermissions() : bool
    {
        $user = $this->getUser();
        if (UserEntity::ROLE_USER != $user->getRole()) {
            return true;
        }
        return false;
    }
}