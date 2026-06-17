<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use App\Event\EventQueue;
use App\Entity\Manager\FirewallRuleManager;
use App\Entity\FirewallRule;
use App\Ufw\Firewall as UfwFirewall;
class FirewallController extends Controller
{
    public function index(Request $request, FirewallRuleManager $firewallRuleManager) : Response
    {
        $rules = $firewallRuleManager->findAll([], ["portRange" => "asc"]);
        $response = $this->render("Admin/Security/Firewall/index.html.twig", ["rules" => $rules]);
        return $response;
    }
    public function addRule(Request $request, FirewallRuleManager $firewallRuleManager) : Response
    {
        $firewallRule = $firewallRuleManager->createEntity();
        $form = $this->createFirewallRuleForm($firewallRule);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleFirewallRuleForm($request, $form, $firewallRuleManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Security/Firewall/add-rule.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createFirewallRuleForm(FirewallRule $firewallRule) : Form
    {
        $form = $this->createForm("App\\Form\\AdminFirewallRuleAddType", $firewallRule, ["action" => $this->generateUrl("clp_admin_firewall_rule_add"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add Rule"]);
        return $form;
    }
    private function handleFirewallRuleForm(Request $request, Form $form, FirewallRuleManager $firewallRuleManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $firewallRule = $form->getData();
                $ufwFirewallPortRange = implode(":", explode("-", $firewallRule->getPortRange()));
                $ufwFirewall = new UfwFirewall();
                $ufwFirewall->allowTcpRule($firewallRule->getSource(), $ufwFirewallPortRange, true);
                $firewallRuleManager->updateEntity($firewallRule);
                if (sprintf("%s-", FirewallRule::FTP_DATA_PORT) == substr($firewallRule->getPortRange(), 0, 3)) {
                    $proftpdPassivePortsFirewallRule = $firewallRuleManager->createEntity();
                    $proftpdPassivePortsFirewallRule->setPortRange(sprintf("%s-%s", FirewallRule::PROFTPD_PASSIVE_PORTS_FROM, FirewallRule::PROFTPD_PASSIVE_PORTS_TO));
                    $proftpdPassivePortsFirewallRule->setSource($firewallRule->getSource());
                    $proftpdPassivePortsFirewallRule->setDescription($firewallRule->getDescription());
                    $firewallRuleManager->updateEntity($proftpdPassivePortsFirewallRule);
                }
                $eventData = ["portRange" => $firewallRule->getPortRange(), "source" => $firewallRule->getSource(), "description" => $firewallRule->getDescription()];
                $this->setUfwFirewallRules($firewallRuleManager);
                EventQueue::addEvent(EventQueue::EVENT_FIREWALL_RULE_CREATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Rule has been added."));
                $response = $this->redirect($this->generateUrl("clp_admin_firewall"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function editRule(Request $request, FirewallRuleManager $firewallRuleManager) : Response
    {
        $id = (int) $request->get("id");
        $firewallRule = $firewallRuleManager->findOneById($id);
        if (true == is_null($firewallRule)) {
            $response = $this->redirect($this->generateUrl("clp_admin_firewall"));
            return $response;
        }
        $form = $this->createFirewallRuleEditForm($firewallRule);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleFirewallRuleEditForm($request, $form, $firewallRuleManager);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/Security/Firewall/edit-rule.html.twig", ["form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createFirewallRuleEditForm(FirewallRule $firewallRule) : Form
    {
        $form = $this->createForm("App\\Form\\AdminFirewallRuleEditType", $firewallRule, ["action" => $this->generateUrl("clp_admin_firewall_rule_edit", ["id" => $firewallRule->getId()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleFirewallRuleEditForm(Request $request, Form $form, FirewallRuleManager $firewallRuleManager)
    {
        if (true === $form->isValid()) {
            try {
                $user = $this->getUser();
                $session = $request->getSession();
                $firewallRule = $form->getData();
                $ufwFirewallPortRange = implode(":", explode("-", $firewallRule->getPortRange()));
                $ufwFirewall = new UfwFirewall();
                $ufwFirewall->allowTcpRule($firewallRule->getSource(), $ufwFirewallPortRange, true);
                $firewallRuleManager->updateEntity($firewallRule);
                $eventData = ["portRange" => $firewallRule->getPortRange(), "source" => $firewallRule->getSource(), "description" => $firewallRule->getDescription()];
                $this->setUfwFirewallRules($firewallRuleManager);
                EventQueue::addEvent(EventQueue::EVENT_FIREWALL_RULE_UPDATE, $user, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("Rule has been updated."));
                $response = $this->redirect($this->generateUrl("clp_admin_firewall"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function deleteRule(Request $request, FirewallRuleManager $firewallRuleManager) : Response
    {
        $id = (int) $request->get("id");
        $firewallRule = $firewallRuleManager->findOneById($id);
        $this->checkCsrfToken($request, "delete-firewall-rule");
        if (false === is_null($firewallRule)) {
            $user = $this->getUser();
            $session = $request->getSession();
            $firewallRuleManager->deleteEntity($firewallRule);
            $eventData = ["portRange" => $firewallRule->getPortRange(), "source" => $firewallRule->getSource(), "description" => $firewallRule->getDescription()];
            $this->setUfwFirewallRules($firewallRuleManager);
            EventQueue::addEvent(EventQueue::EVENT_FIREWALL_RULE_DELETE, $user, $eventData, $request);
            $session->getFlashBag()->set("success", $this->translator->trans("Rule has been deleted."));
        }
        $response = $this->redirect($this->generateUrl("clp_admin_firewall"));
        return $response;
    }
    private function setUfwFirewallRules(FirewallRuleManager $firewallRuleManager) : void
    {
        $firewallRules = $firewallRuleManager->findAll();
        $ufwFirewall = new UfwFirewall();
        $ufwFirewall->reset();
        if (count($firewallRules)) {
            foreach ($firewallRules as $firewallRule) {
                $portRange = implode(":", explode("-", $firewallRule->getPortRange()));
                $ufwFirewall->allowTcpRule($firewallRule->getSource(), $portRange);
            }
            $ufwFirewall->allowUdpRule("0.0.0.0/0", 443);
            $ufwFirewall->allowUdpRule("::/0", 443);
            $ufwFirewall->enable();
        } else {
            $ufwFirewall->disable();
        }
    }
}