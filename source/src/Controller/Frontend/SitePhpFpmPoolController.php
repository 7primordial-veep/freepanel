<?php

namespace App\Controller\Frontend;

use App\Controller\Controller;
use App\Controller\Frontend\SiteAccessTrait;
use App\Entity\Manager\SiteManager;
use App\Entity\Site;
use App\Event\EventQueue;
use App\Form\SitePhpFpmPoolType;
use App\Service\Logger;
use App\Site\PhpFpm\PoolConfigWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class SitePhpFpmPoolController extends Controller
{
    use SiteAccessTrait;

    private SiteManager $siteManager;
    private PoolConfigWriter $writer;

    public function __construct(
        SiteManager $siteManager,
        PoolConfigWriter $writer,
        TranslatorInterface $t,
        Logger $l
    ) {
        $this->siteManager = $siteManager;
        $this->writer = $writer;
        parent::__construct($t, $l);
    }

    public function index(Request $request) : Response
    {
        $site = $this->resolveSite($request);
        $session = $request->getSession();

        if (Site::TYPE_PHP !== $site->getType()) {
            $session->getFlashBag()->set(
                'warning',
                $this->translator->trans('PHP-FPM pool tuning only available for PHP sites')
            );
            return $this->redirect($this->generateUrl('clp_site_settings', ['domainName' => $site->getDomainName()]));
        }

        $phpSettings = $site->getPhpSettings();
        $phpVersion = $phpSettings ? (string) $phpSettings->getPhpVersion() : '';
        $siteUser = (string) $site->getUser();

        $defaults = [
            'pm'                => 'dynamic',
            'pmMaxChildren'     => 10,
            'pmStartServers'    => 2,
            'pmMinSpareServers' => 1,
            'pmMaxSpareServers' => 3,
            'pmMaxRequests'     => 500,
        ];

        $current = $this->readCurrentPool($phpVersion, $siteUser, $defaults);

        $form = $this->createForm(SitePhpFpmPoolType::class, $current);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->writer->apply($siteUser, $phpVersion, $data);

                $user = $this->getUser();
                if (null !== $user) {
                    EventQueue::addEvent(
                        EventQueue::EVENT_SITE_PHP_FPM_POOL_UPDATE,
                        $user,
                        ['domain' => $site->getDomainName(), 'settings' => $data],
                        $request
                    );
                }

                $session->getFlashBag()->set(
                    'success',
                    $this->translator->trans('PHP-FPM pool updated.')
                );
                return $this->redirect($this->generateUrl('clp_site_php_fpm_pool', ['domainName' => $site->getDomainName()]));
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set(
                    'danger',
                    $this->translator->trans(
                        'Failed to apply PHP-FPM pool: %errorMessage%',
                        ['%errorMessage%' => $e->getMessage()]
                    )
                );
            }
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            $this->formErrors = $this->getErrorMessages($form);
        }

        return $this->render('Frontend/Site/php-fpm-pool.html.twig', [
            'site'       => $site,
            'form'       => $form->createView(),
            'formErrors' => $this->formErrors,
        ]);
    }

    private function resolveSite(Request $request) : Site
    {
        $domainName = (string) $request->get('domainName');
        $site = $this->siteManager->findOneBy(['domainName' => $domainName]);
        if (null === $site) {
            throw $this->createNotFoundException();
        }

        $this->denyUnlessSiteOwnerOrAdmin($site);

        return $site;
    }

    /**
     * @param array{pm:string,pmMaxChildren:int,pmStartServers:int,pmMinSpareServers:int,pmMaxSpareServers:int,pmMaxRequests:int} $defaults
     * @return array{pm:string,pmMaxChildren:int,pmStartServers:int,pmMinSpareServers:int,pmMaxSpareServers:int,pmMaxRequests:int}
     */
    private function readCurrentPool(string $phpVersion, string $siteUser, array $defaults) : array
    {
        if (!preg_match('/^\d+\.\d+$/', $phpVersion) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $siteUser)) {
            return $defaults;
        }
        $path = sprintf('/etc/php/%s/fpm/pool.d/%s.conf', $phpVersion, $siteUser);
        $cmd = sprintf('/usr/bin/sudo /bin/cat %s 2>/dev/null', escapeshellarg($path));
        $contents = @shell_exec($cmd);
        if (!is_string($contents) || '' === trim($contents)) {
            return $defaults;
        }

        $map = [
            'pm'                   => 'pm',
            'pm.max_children'      => 'pmMaxChildren',
            'pm.start_servers'     => 'pmStartServers',
            'pm.min_spare_servers' => 'pmMinSpareServers',
            'pm.max_spare_servers' => 'pmMaxSpareServers',
            'pm.max_requests'      => 'pmMaxRequests',
        ];

        $result = $defaults;
        $lines = preg_split('/\R/', $contents) ?: [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ('' === $trimmed || $trimmed[0] === ';' || $trimmed[0] === '#') {
                continue;
            }
            foreach ($map as $directive => $field) {
                if (preg_match('/^' . preg_quote($directive, '/') . '\s*=\s*(\S+)/', $trimmed, $m)) {
                    if ($field === 'pm') {
                        $val = strtolower($m[1]);
                        if (in_array($val, ['dynamic', 'static', 'ondemand'], true)) {
                            $result['pm'] = $val;
                        }
                    } else {
                        $result[$field] = (int) $m[1];
                    }
                    break;
                }
            }
        }

        return $result;
    }
}
