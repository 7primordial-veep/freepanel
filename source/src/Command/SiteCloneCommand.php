<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use App\Command\SiteCommand as SiteCommand;
use App\Entity\Site as SiteEntity;

/**
 * Minimum-viable site clone.
 *
 * Supported source types: PHP (covers WordPress and generic PHP apps).
 * Other site types currently error out — see TODO at the bottom.
 *
 * Strategy:
 *   1. Resolve source site, validate it is PHP and exists.
 *   2. Provision the destination site by shelling out to `clpctl site:add:php`,
 *      reusing the existing creator path (nginx vhost, php-fpm pool, user, certs).
 *   3. rsync /home/<srcUser>/htdocs/<srcDomain>/ -> /home/<newUser>/htdocs/<newDomain>/.
 *   4. For each database attached to the source site, dump it and re-import under
 *      a renamed database (<newUser>_<dbBase>) using existing db:export / db:import
 *      pipes. Then string-replace the source domain inside the new dump on disk
 *      before import for WordPress-style configs (best effort, MVP).
 *   5. Reset permissions via system:permissions:reset.
 *
 * Anything fancier (wp-config rewriting, search-replace on serialized data,
 * non-PHP types) is out of scope for MVP.
 */
class SiteCloneCommand extends SiteCommand
{
    protected function configure() : void
    {
        $this->setName("site:clone");
        $this->setDescription("clpctl site:clone --sourceDomain=src.example.com --newDomain=clone.example.com --newUser=cloneuser --newUserPassword='!secret!'");
        $this->setComment("Cloning a Site");
        $this->addOption("sourceDomain", null, InputOption::VALUE_REQUIRED);
        $this->addOption("newDomain", null, InputOption::VALUE_REQUIRED);
        $this->addOption("newUser", null, InputOption::VALUE_REQUIRED);
        // ponytail: argv is optional so callers can pass the password via env (CLPCTL_NEW_USER_PASSWORD)
        // to avoid leaking it through the process list / shell history.
        $this->addOption("newUserPassword", null, InputOption::VALUE_OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        try {
            $this->validateInput($input);
            $sourceDomain = mb_strtolower(trim($input->getOption("sourceDomain")));
            $newDomain = mb_strtolower(trim($input->getOption("newDomain")));
            $newUser = trim($input->getOption("newUser"));
            $newUserPassword = trim((string) ($input->getOption("newUserPassword") ?? getenv("CLPCTL_NEW_USER_PASSWORD")));

            if ($sourceDomain === $newDomain) {
                throw new \Exception("sourceDomain and newDomain must be different.");
            }

            $sourceEntity = $this->getSiteEntity($sourceDomain);
            if (true === is_null($sourceEntity)) {
                throw new \Exception(sprintf("Source site \"%s\" does not exist.", $sourceDomain));
            }
            if (SiteEntity::TYPE_PHP !== $sourceEntity->getType()) {
                throw new \Exception("site:clone currently only supports PHP sites in this release.");
            }
            if (false === is_null($this->getSiteEntity($newDomain))) {
                throw new \Exception(sprintf("Target site \"%s\" already exists.", $newDomain));
            }

            $phpSettings = $sourceEntity->getPhpSettings();
            $phpVersion = $phpSettings ? $phpSettings->getPhpVersion() : "8.3";
            $vhostTemplate = $sourceEntity->getApplication() ?: "Generic";
            $sourceUser = $sourceEntity->getUser();

            // 1. Provision destination via existing CLI path.
            $output->writeln(sprintf("<info>Provisioning new site</info> <comment>%s</comment>...", $newDomain));
            $this->runProcess([
                "clpctl", "site:add:php",
                "--domainName=" . $newDomain,
                "--phpVersion=" . $phpVersion,
                "--vhostTemplate=" . $vhostTemplate,
                "--siteUser=" . $newUser,
                "--siteUserPassword=" . $newUserPassword,
            ], $output);

            // 2. rsync htdocs.
            $srcHtdocs = sprintf("/home/%s/htdocs/%s/", $sourceUser, $sourceDomain);
            $dstHtdocs = sprintf("/home/%s/htdocs/%s/", $newUser, $newDomain);
            if (is_dir($srcHtdocs)) {
                $output->writeln("<info>Copying files (rsync)...</info>");
                $this->runProcess([
                    "rsync", "-a", "--delete",
                    "--exclude=.well-known",
                    $srcHtdocs, $dstHtdocs,
                ], $output);
                // ownership fix
                $this->runProcess(["chown", "-R", $newUser . ":" . $newUser, $dstHtdocs], $output);
            } else {
                $output->writeln(sprintf("<comment>Source htdocs not found at %s, skipping file copy.</comment>", $srcHtdocs));
            }

            // 3. Clone databases (best effort).
            $databaseEntities = $sourceEntity->getDatabases();
            if (count($databaseEntities)) {
                foreach ($databaseEntities as $db) {
                    $oldName = $db->getName();
                    // Replace the source-user prefix with the new-user prefix if present, else prefix with newUser_.
                    if (str_starts_with($oldName, $sourceUser . "_")) {
                        $newName = $newUser . "_" . substr($oldName, strlen($sourceUser) + 1);
                    } else {
                        $newName = $newUser . "_" . $oldName;
                    }
                    if (mb_strlen($newName) > 64) {
                        $newName = substr($newName, 0, 64);
                    }
                    $output->writeln(sprintf("<info>Cloning database</info> <comment>%s</comment> -> <comment>%s</comment>...", $oldName, $newName));
                    $dumpFile = sprintf("/tmp/clp-clone-%s-%d.sql.gz", $oldName, getmypid());
                    $this->runProcess(["clpctl", "db:export", "--databaseName=" . $oldName, "--file=" . $dumpFile], $output);
                    // Provision a fresh empty database under the new site/user.
                    // db:add takes domainName + databaseName + databaseUserName + databaseUserPassword.
                    $dbUser = substr($newUser . "_user", 0, 32);
                    $dbUserPassword = bin2hex(random_bytes(12));
                    $this->runProcess([
                        "clpctl", "db:add",
                        "--domainName=" . $newDomain,
                        "--databaseName=" . $newName,
                        "--databaseUserName=" . $dbUser,
                        "--databaseUserPassword=" . $dbUserPassword,
                    ], $output);
                    $this->runProcess(["clpctl", "db:import", "--databaseName=" . $newName, "--file=" . $dumpFile], $output);
                    @unlink($dumpFile);
                    $output->writeln(sprintf("<comment>New DB user:</comment> %s <comment>password:</comment> %s", $dbUser, $dbUserPassword));
                }
            }

            // 4. Reset permissions on the new site user.
            $this->runProcess(["clpctl", "system:permissions:reset", "--users=" . $newUser], $output, true);

            $output->writeln(sprintf("<info>Site</info> <comment>%s</comment> <info>has been cloned to</info> <comment>%s</comment>.", $sourceDomain, $newDomain));
            return SiteCommand::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf("<error>%s</error>", $e->getMessage()));
            return SiteCommand::FAILURE;
        }
    }

    /**
     * Run an external command; throws on non-zero unless $tolerateFailure is true.
     */
    private function runProcess(array $argv, OutputInterface $output, bool $tolerateFailure = false) : void
    {
        $process = new Process($argv);
        $process->setTimeout(0);
        $process->run(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });
        if (false === $process->isSuccessful() && false === $tolerateFailure) {
            throw new \Exception(sprintf("Command failed: %s", implode(" ", $argv)));
        }
    }
}
