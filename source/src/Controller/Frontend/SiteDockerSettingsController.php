<?php

namespace App\Controller\Frontend;

use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Entity\User as UserEntity;
use App\Form\SiteEditDockerType;
use App\Service\Logger;
use App\System\Command\DockerRunCommand;
use App\System\CommandExecutor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class SiteDockerSettingsController extends Controller
{
    private SiteManager $siteManager;
    private CommandExecutor $commandExecutor;

    public function __construct(
        SiteManager $siteManager,
        CommandExecutor $commandExecutor,
        TranslatorInterface $t,
        Logger $l
    ) {
        $this->siteManager = $siteManager;
        $this->commandExecutor = $commandExecutor;
        parent::__construct($t, $l);
    }

    public function edit(Request $request): Response
    {
        $domainName = (string) $request->get('domainName');
        $session = $request->getSession();

        $site = $this->siteManager->findOneBy(['domainName' => $domainName]);
        if (null === $site || 'docker' !== $site->getType()) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        // IDOR guard: only the site owner or a panel admin may view or modify Docker settings.
        $user = $this->getUser();
        if (null === $user) {
            throw $this->createAccessDeniedException();
        }
        if (UserEntity::ROLE_USER == $user->getRole() && false === $user->hasSite($site)) {
            throw $this->createAccessDeniedException();
        }

        $initialData = [
            'dockerImage'      => $site->getDockerImage(),
            'dockerPort'       => $site->getDockerPort(),
            'dockerEnvRaw'     => $this->envArrayToRaw($site->getDockerEnv()),
            'dockerVolumesRaw' => $this->volumesArrayToRaw($site->getDockerVolumes()),
        ];

        $form = $this->createForm(SiteEditDockerType::class, $initialData, [
            'action' => $this->generateUrl('clp_site_docker_settings', ['domainName' => $domainName]),
            'method' => 'POST',
            'attr'   => ['id' => 'docker-settings-form'],
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $image = isset($data['dockerImage']) ? (string) $data['dockerImage'] : null;
                    $port = isset($data['dockerPort']) ? (int) $data['dockerPort'] : null;
                    $env = $this->parseEnvRaw((string) ($data['dockerEnvRaw'] ?? ''));
                    $volumes = $this->parseVolumesRaw((string) ($data['dockerVolumesRaw'] ?? ''));
                    // Confine all host mounts to the site's volumes jail and forbid sensitive paths.
                    // Throws on any disallowed mapping so the whole submission fails closed.
                    $this->assertVolumesAllowed($volumes, (string) $site->getUser());

                    if (null !== $image) {
                        $site->setDockerImage($image);
                    }
                    if (null !== $port) {
                        $site->setDockerPort($port);
                    }
                    $site->setDockerEnv($env);
                    $site->setDockerVolumes($volumes);

                    $this->siteManager->updateEntity($site);

                    $containerName = $site->getDockerContainerName() ?: sprintf('clp-%s', $site->getUser());
                    if (!empty($image) && null !== $port && !empty($containerName)) {
                        $cmd = new DockerRunCommand();
                        $cmd->setContainerName($containerName);
                        $cmd->setImage($image);
                        $cmd->setHostPort((int) $port);
                        $cmd->setEnv($env);
                        $cmd->setVolumes($volumes);
                        $this->commandExecutor->execute($cmd, 300);
                    }

                    $session->getFlashBag()->set('success', $this->translator->trans('Docker settings saved.'));
                } catch (\Exception $e) {
                    $this->logger->exception($e);
                    $session->getFlashBag()->set(
                        'danger',
                        $this->translator->trans(
                            'An error has occurred, error message: %errorMessage%',
                            ['%errorMessage%' => $e->getMessage()]
                        )
                    );
                }
                return $this->redirect($this->generateUrl('clp_site_docker_settings', ['domainName' => $domainName]));
            }
            $this->formErrors = $this->getErrorMessages($form);
        }

        return $this->render('Frontend/Site/Docker/settings.html.twig', [
            'site'       => $site,
            'form'       => $form->createView(),
            'formErrors' => $this->formErrors,
        ]);
    }

    /**
     * Parse multi-line KEY=VALUE input (comma-separated per line) into an associative array.
     *
     * @return array<string,string>
     */
    private function parseEnvRaw(string $raw): array
    {
        $env = [];
        if ('' === trim($raw)) {
            return $env;
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $pairs = explode(',', $line);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if ('' === $pair || false === strpos($pair, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $pair, 2);
                $k = trim($k);
                $v = trim($v);
                if ('' === $k) {
                    continue;
                }
                $env[$k] = $v;
            }
        }
        return $env;
    }

    /**
     * Parse one host:container mapping per line into a list of {host, container} arrays.
     *
     * @return array<int,array{host:string,container:string}>
     */
    private function parseVolumesRaw(string $raw): array
    {
        $volumes = [];
        if ('' === trim($raw)) {
            return $volumes;
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || false === strpos($line, ':')) {
                continue;
            }
            [$host, $container] = explode(':', $line, 2);
            $host = trim($host);
            $container = trim($container);
            if ('' === $host || '' === $container) {
                continue;
            }
            $volumes[] = ['host' => $host, 'container' => $container];
        }
        return $volumes;
    }

    /**
     * Enforce: every host mount must resolve inside /home/<siteUser>/volumes/.
     * Symlinks pointing out, sensitive paths (/etc, /root, /proc, /sys, /var/run/docker.sock,
     * /var/lib/*, /), and any non-existent or relative paths are all rejected. The
     * site user must be non-empty and match the on-disk jail.
     *
     * @param array<int,array{host:string,container:string}> $volumes
     */
    private function assertVolumesAllowed(array $volumes, string $siteUser): void
    {
        if (empty($volumes)) {
            return;
        }
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $siteUser)) {
            throw new \RuntimeException('Refusing to apply volumes: invalid site user.');
        }
        $jailBase = sprintf('/home/%s/volumes', $siteUser);
        $jailReal = realpath($jailBase);
        if ($jailReal === false) {
            // Don't create silently — operator must provision the jail (install hook
            // or via console) before mounts are accepted.
            throw new \RuntimeException(sprintf(
                'Refusing to apply volumes: jail %s does not exist. Create it as the site user first.',
                $jailBase
            ));
        }
        $blocklist = [
            '/', '/etc', '/root', '/proc', '/sys', '/dev',
            '/var', '/var/run', '/var/run/docker.sock', '/var/lib',
            '/home', '/usr', '/boot', '/run',
        ];
        foreach ($volumes as $vol) {
            $host = (string) ($vol['host'] ?? '');
            $container = (string) ($vol['container'] ?? '');
            if ('' === $host || '' === $container) {
                throw new \RuntimeException('Refusing to apply volumes: empty host or container path.');
            }
            if ('/' !== $host[0]) {
                throw new \RuntimeException(sprintf('Refusing to apply volumes: host path %s is not absolute.', $host));
            }
            if (false !== strpos($host, "\0") || false !== strpos($host, '..')) {
                throw new \RuntimeException(sprintf('Refusing to apply volumes: host path %s contains traversal.', $host));
            }
            if (in_array($host, $blocklist, true)) {
                throw new \RuntimeException(sprintf('Refusing to apply volumes: %s is a blocklisted host path.', $host));
            }
            $hostReal = realpath($host);
            if ($hostReal === false) {
                throw new \RuntimeException(sprintf(
                    'Refusing to apply volumes: host path %s does not exist (must be under %s).',
                    $host, $jailReal
                ));
            }
            // Strict prefix at directory boundary: $hostReal must equal $jailReal or be $jailReal/...
            if ($hostReal !== $jailReal && strncmp($hostReal, $jailReal . '/', strlen($jailReal) + 1) !== 0) {
                throw new \RuntimeException(sprintf(
                    'Refusing to apply volumes: %s is outside the site jail %s.',
                    $host, $jailReal
                ));
            }
        }
    }

    /**
     * @param array<string,string> $env
     */
    private function envArrayToRaw(array $env): string
    {
        if (empty($env)) {
            return '';
        }
        $pairs = [];
        foreach ($env as $k => $v) {
            $pairs[] = sprintf('%s=%s', $k, $v);
        }
        return implode(',', $pairs);
    }

    /**
     * @param array<int,array{host:string,container:string}> $volumes
     */
    private function volumesArrayToRaw(array $volumes): string
    {
        if (empty($volumes)) {
            return '';
        }
        $lines = [];
        foreach ($volumes as $vol) {
            if (empty($vol['host']) || empty($vol['container'])) {
                continue;
            }
            $lines[] = sprintf('%s:%s', $vol['host'], $vol['container']);
        }
        return implode("\n", $lines);
    }
}
