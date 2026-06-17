<?php

namespace App\Controller\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use App\Event\EventQueue;
use App\Service\Crypto;
use App\Service\Logger;
use App\Entity\Manager\DatabaseServerManager as DatabaseServerEntityManager;
use App\Database\Connection as DatabaseConnection;
use App\Entity\Manager\ConfigManager;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\DatabaseServer as DatabaseServerEntity;
use App\Security\Admin\CustomDomain as AdminCustomDomain;
use App\Site\Ssl\DistinguishedName;
use App\Site\Ssl\Generator\RsaKeyGenerator;
use App\Site\Ssl\Generator\CsrGenerator;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\Certificate;
use App\Site\Ssl\LetsEncryptClient;
use App\Site\Ssl\Dns\DnsProviderFactory;
use App\Site\Ssl\Util\Openssl;
class SettingsController extends Controller
{
    private ConfigManager $configManager;
    private DatabaseServerEntityManager $databaseServerEntityManager;
    public function __construct(ConfigManager $configManager, DatabaseServerEntityManager $databaseServerEntityManager, TranslatorInterface $translator, Logger $logger)
    {
        $this->configManager = $configManager;
        $this->databaseServerEntityManager = $databaseServerEntityManager;
        parent::__construct($translator, $logger);
    }
    public function settings(Request $request) : Response
    {
        $customDomainName = $this->configManager->get("custom_domain");
        $customDomainData = ["domainName" => false === is_null($customDomainName) ? $customDomainName : ''];
        $customDomainForm = $this->createCustomDomainForm($customDomainData);
        if (true === $request->isMethod("POST")) {
            $customDomainForm->handleRequest($request);
            if (true === $customDomainForm->isSubmitted()) {
                $response = $this->handleCustomDomainForm($request, $customDomainForm);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Settings/index.html.twig", ["customDomainForm" => $customDomainForm->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createCustomDomainForm(array $data) : Form
    {
        $form = $this->createForm("App\\Form\\AdminCustomDomainSettingsType", $data, ["action" => $this->generateUrl("clp_admin_settings"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleCustomDomainForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $domainName = $form->get("domainName")->getData();
                $adminCustomDomain = new AdminCustomDomain();
                if (!(false === empty($domainName))) {
                    $adminCustomDomain = new AdminCustomDomain();
                    $adminCustomDomain->delete();
                    $this->configManager->delete("custom_domain");
                    EventQueue::addEvent(EventQueue::EVENT_ADMIN_CUSTOM_DOMAIN_DISABLE, $user, [], $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("CloudPanel Custom Domain has been saved."));
                    $response = $this->redirect($this->generateUrl("clp_admin_settings"));
                    return $response;
                }
                $domains = [$domainName];
                $letsEncryptPrivateKey = $this->configManager->get("le_private_key");
                $privateKey = new PrivateKey($letsEncryptPrivateKey);
                $letsEncryptClient = new LetsEncryptClient($privateKey);
                $letsEncryptClient->registerAccount();
                $certificateOrder = $letsEncryptClient->requestOrder($domains);
                $vhostTemplateFile = realpath(dirname(__FILE__) . "/../../../resources/nginx/vhost_template/custom-domain");
                $vhostTemplate = file_get_contents($vhostTemplateFile);
                $distinguishedNameDomains = $domains;
                $commonName = array_shift($distinguishedNameDomains);
                $distinguishedName = new DistinguishedName($commonName, $distinguishedNameDomains);
                $rsaKeyGenerator = new RsaKeyGenerator();
                $privateKey = $rsaKeyGenerator->generatePrivateKey();
                $csrGenerator = new CsrGenerator($privateKey, $distinguishedName);
                $csr = $csrGenerator->generate();
                $selfSignedCertificate = Openssl::createSelfSignedCertificate($privateKey, $csr);
                $certificate = new Certificate();
                $certificate->setPrivateKey($privateKey->getPEM());
                $certificate->setCertificate($selfSignedCertificate);
                $adminCustomDomain->deleteLetsEncryptChallengeFiles();
                $adminCustomDomain->createLetsEncryptChallengeFiles($certificateOrder);
                $adminCustomDomain->writePrivateKeyAndCertificate($certificate);
                $adminCustomDomain->writeVhostFile($domainName, $vhostTemplate);
                $adminCustomDomain->reloadNginx();
                $validationErrors = $letsEncryptClient->validateDomains($certificateOrder);
                if (true === empty($validationErrors)) {
                    $certificate = $letsEncryptClient->finalizeOrder($certificateOrder, $privateKey, $csr);
                    $adminCustomDomain->writePrivateKeyAndCertificate($certificate);
                    $adminCustomDomain->writeVhostFile($domainName, $vhostTemplate);
                    $adminCustomDomain->writeMotdFile($domainName);
                    $adminCustomDomain->reloadNginx();
                    $this->configManager->set("custom_domain", $domainName);
                    $eventData = ["domainName" => $domainName];
                    EventQueue::addEvent(EventQueue::EVENT_ADMIN_CUSTOM_DOMAIN_ENABLE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("CloudPanel Custom Domain has been saved."));
                    $response = $this->redirect($this->generateUrl("clp_admin_settings"));
                    return $response;
                } else {
                    $adminCustomDomain = new AdminCustomDomain();
                    $adminCustomDomain->delete();
                    foreach ($validationErrors as $domain => $validationErrorMessage) {
                        $form->addError(new FormError(sprintf("%s: %s", $domain, $validationErrorMessage)));
                    }
                    $this->formErrors = $this->getErrorMessages($form);
                }
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            } finally {
                if (true === isset($adminCustomDomain)) {
                    $adminCustomDomain->deleteLetsEncryptChallengeFiles();
                }
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function databaseServers(Request $request) : Response
    {
        $databaseServers = $this->databaseServerEntityManager->findAll([], ["host" => "ASC"]);
        $response = $this->render("Admin/Settings/Database-Servers/index.html.twig", ["databaseServers" => $databaseServers]);
        return $response;
    }
    public function addDatabaseServer(Request $request) : Response
    {
        $databaseServerEntity = $this->databaseServerEntityManager->createEntity();
        $form = $this->createDatabaseServerForm($databaseServerEntity);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleDatabaseServerForm($request, $form);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Settings/Database-Servers/add.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createDatabaseServerForm(DatabaseServerEntity $databaseServerEntity) : Form
    {
        $form = $this->createForm("App\\Form\\AdminDatabaseServerType", $databaseServerEntity, ["action" => $this->generateUrl("clp_admin_database_server_add"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add Database Server"]);
        return $form;
    }
    private function handleDatabaseServerForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $databaseServerEntity = $form->getData();
                $password = $databaseServerEntity->getPassword();
                $encryptedPassword = Crypto::encrypt($password);
                $databaseServerEntity->setPassword($encryptedPassword);
                $databaseConnection = new DatabaseConnection($databaseServerEntity);
                $engine = $databaseConnection->getEngine();
                $version = $databaseConnection->getVersion();
                $databaseServerEntity->setEngine($engine);
                $databaseServerEntity->setVersion($version);
                $databaseServerEntity->setIsDefault(false);
                $eventData = ["host" => $databaseServerEntity->getHost(), "userName" => $databaseServerEntity->getUserName(), "port" => $databaseServerEntity->getPort(), "engine" => $engine, "version" => $version];
                EventQueue::addEvent(EventQueue::EVENT_DATABASE_SERVER_ADD, $user, $eventData, $request);
                $this->databaseServerEntityManager->updateEntity($databaseServerEntity);
                $session->getFlashBag()->set("success", $this->translator->trans("Database Server has been added."));
                $response = $this->redirect($this->generateUrl("clp_admin_database_servers"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function editDatabaseServer(Request $request) : Response
    {
        $id = (int) $request->get("id");
        $databaseServerEntity = $this->databaseServerEntityManager->findOneById($id);
        if (!(true === is_null($databaseServerEntity))) {
            $databaseServerEntity->setPassword('');
            $form = $this->createDatabaseServerEditForm($databaseServerEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleDatabaseServerEditForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Admin/Settings/Database-Servers/edit.html.twig", ["databaseServer" => $databaseServerEntity, "form" => $form->createView(), "formErrors" => $this->formErrors]);
            return $response;
        }
        $response = $this->redirect($this->generateUrl("clp_admin_database_servers"));
        return $response;
    }
    private function createDatabaseServerEditForm(DatabaseServerEntity $databaseServerEntity) : Form
    {
        $form = $this->createForm("App\\Form\\AdminDatabaseServerEditType", $databaseServerEntity, ["action" => $this->generateUrl("clp_admin_database_server_edit", ["id" => $databaseServerEntity->getId()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleDatabaseServerEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $databaseServerEntity = $form->getData();
                $password = $databaseServerEntity->getPassword();
                $encryptedPassword = Crypto::encrypt($password);
                $databaseServerEntity->setPassword($encryptedPassword);
                $databaseConnection = new DatabaseConnection($databaseServerEntity);
                $engine = $databaseConnection->getEngine();
                $version = $databaseConnection->getVersion();
                $databaseServerEntity->setEngine($engine);
                $databaseServerEntity->setVersion($version);
                $databaseServerEntity->setIsDefault(false);
                $eventData = ["host" => $databaseServerEntity->getHost(), "userName" => $databaseServerEntity->getUserName(), "port" => $databaseServerEntity->getPort(), "engine" => $engine, "version" => $version];
                EventQueue::addEvent(EventQueue::EVENT_DATABASE_SERVER_EDIT, $user, $eventData, $request);
                $this->databaseServerEntityManager->updateEntity($databaseServerEntity);
                $session->getFlashBag()->set("success", $this->translator->trans("Database Server has been updated."));
                $response = $this->redirect($this->generateUrl("clp_admin_database_servers"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function setDatabaseServerActive(Request $request) : Response
    {
        $this->checkCsrfToken($request, "set-active-database-server");
        $id = (int) $request->get("id");
        $databaseServerEntity = $this->databaseServerEntityManager->findOneById($id);
        if (false === is_null($databaseServerEntity)) {
            $activeDatabaseServerEntity = $this->databaseServerEntityManager->getActiveDatabaseServer();
            if ($databaseServerEntity->getId() != $activeDatabaseServerEntity->getId()) {
                try {
                    $user = $this->getUser();
                    $session = $request->getSession();
                    $databaseServerEntity->setIsActive(true);
                    $activeDatabaseServerEntity->setIsActive(false);
                    $this->databaseServerEntityManager->updateEntity($databaseServerEntity);
                    $this->databaseServerEntityManager->updateEntity($activeDatabaseServerEntity);
                    $eventData = ["oldHost" => $activeDatabaseServerEntity->getHost(), "oldUserName" => $activeDatabaseServerEntity->getUserName(), "newHost" => $databaseServerEntity->getHost(), "newUserName" => $databaseServerEntity->getUserName()];
                    EventQueue::addEvent(EventQueue::EVENT_DATABASE_SERVER_CHANGE, $user, $eventData, $request);
                    $session->getFlashBag()->set("success", $this->translator->trans("Database Server has been changed."));
                } catch (\Exception $e) {
                    $session->getFlashBag()->set("danger", $this->translator->trans("Database Server credentials are not valid."));
                }
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_database_servers"));
        return $response;
    }
    public function deleteDatabaseServer(Request $request) : Response
    {
        $this->checkCsrfToken($request, "delete-database-server");
        $id = (int) $request->get("id");
        $databaseServerEntity = $this->databaseServerEntityManager->findOneById($id);
        if (false === is_null($databaseServerEntity) && false === $databaseServerEntity->isDefault() && false === $databaseServerEntity->isActive()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $this->databaseServerEntityManager->deleteEntity($databaseServerEntity);
                $eventData = ["host" => $databaseServerEntity->getHost(), "userName" => $databaseServerEntity->getUserName()];
                EventQueue::addEvent(EventQueue::EVENT_DATABASE_SERVER_DELETE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Database Server has been deleted."));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_database_servers"));
        return $response;
    }
}