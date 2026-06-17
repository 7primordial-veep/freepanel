<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Command\Command as BaseCommand;
use App\Cloudflare\Client as CloudflareClient;

/**
 * clpctl cloudflare:dns:create --domainName=foo.example.com --ipAddress=1.2.3.4 [--type=A] [--proxied=true]
 *
 * Picks the right zone automatically (longest-suffix match on listZones()).
 * Used by SiteAddPhpCommand-style flows when the operator wires it in via
 * --post-create-hook, and is also runnable standalone.
 */
class CloudflareDnsCreateCommand extends BaseCommand
{
    private CloudflareClient $cloudflare;

    public function __construct(CloudflareClient $cloudflare)
    {
        parent::__construct();
        $this->cloudflare = $cloudflare;
    }

    protected function configure(): void
    {
        $this->setName("cloudflare:dns:create");
        $this->setDescription("clpctl cloudflare:dns:create --domainName=... --ipAddress=...");
        $this->addOption("domainName", null, InputOption::VALUE_REQUIRED);
        $this->addOption("ipAddress", null, InputOption::VALUE_REQUIRED);
        $this->addOption("type", null, InputOption::VALUE_OPTIONAL, '', "A");
        $this->addOption("proxied", null, InputOption::VALUE_OPTIONAL, '', "true");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->validateInput($input);
            $domainName = (string) $input->getOption("domainName");
            $ipAddress = (string) $input->getOption("ipAddress");
            $type = strtoupper((string) $input->getOption("type"));
            $proxied = filter_var($input->getOption("proxied"), FILTER_VALIDATE_BOOLEAN);
            if (false === $this->cloudflare->isConfigured()) {
                throw new \RuntimeException("Cloudflare API token is not configured (see /admin/cloudflare/settings).");
            }
            $zone = $this->cloudflare->findZoneForDomain($domainName);
            if (null === $zone) {
                throw new \RuntimeException(sprintf("No Cloudflare zone matches '%s'.", $domainName));
            }
            $this->cloudflare->addDnsRecord($zone["id"], $type, $domainName, $ipAddress, $proxied);
            $output->writeln(sprintf("<info>Created %s record %s -> %s in zone %s%s.</info>", $type, $domainName, $ipAddress, $zone["name"], $proxied ? " (proxied)" : ''));
            return BaseCommand::SUCCESS;
        } catch (\Throwable $e) {
            $this->getLogger()->exception($e);
            $output->writeln(sprintf("<error>%s</error>", $e->getMessage()));
            return BaseCommand::FAILURE;
        }
    }
}
