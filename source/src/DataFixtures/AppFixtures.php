<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Timezone;
use App\Entity\FirewallRule;
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager) : void
    {
        $this->loadTimezones($manager);
        $this->loadFirewallRules($manager);
        $manager->flush();
    }
    private function loadTimezones(ObjectManager $manager) : void
    {
        $timezones = timezone_identifiers_list();
        foreach ($timezones as $timezoneName) {
            $timezoneName = trim($timezoneName);
            $timezone = new Timezone();
            $timezone->setName($timezoneName);
            $manager->persist($timezone);
        }
        $manager->flush();
    }
    private function loadFirewallRules(ObjectManager $manager) : void
    {
        $defaultPorts = [80, 443, 8443, 22];
        foreach ($defaultPorts as $port) {
            $firewallRuleIpv4 = new FirewallRule();
            $firewallRuleIpv4->setPortRange($port);
            $firewallRuleIpv4->setSource("0.0.0.0/0");
            $firewallRuleIpv6 = new FirewallRule();
            $firewallRuleIpv6->setPortRange($port);
            $firewallRuleIpv6->setSource("::/0");
            $manager->persist($firewallRuleIpv4);
            $manager->persist($firewallRuleIpv6);
        }
        $manager->flush();
    }
}