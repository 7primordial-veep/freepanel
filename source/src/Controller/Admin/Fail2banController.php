<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Controller\Controller;
use App\Fail2ban\Fail2ban;

class Fail2banController extends Controller
{
    public function index(Request $request): Response
    {
        $fail2ban = new Fail2ban();
        $status = $fail2ban->getStatus();
        $jails = [];
        foreach ($status['jails'] as $jail) {
            $jails[] = $fail2ban->getJailStatus($jail);
        }
        return $this->render('Admin/Security/Fail2ban/index.html.twig', [
            'running' => $status['running'],
            'raw' => $status['raw'],
            'jails' => $jails,
        ]);
    }

    public function unban(Request $request): Response
    {
        $this->checkCsrfToken($request, 'fail2ban-unban');
        $jail = (string) $request->query->get('jail');
        $ip = (string) $request->query->get('ip');
        $session = $request->getSession();
        if ($jail === '' || $ip === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $jail) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $session->getFlashBag()->set('danger', $this->translator->trans('Invalid jail or IP.'));
            return $this->redirect($this->generateUrl('clp_admin_fail2ban'));
        }
        try {
            $fail2ban = new Fail2ban();
            $fail2ban->unban($jail, $ip);
            $session->getFlashBag()->set('success', $this->translator->trans('IP %ip% has been unbanned from %jail%.', ['%ip%' => $ip, '%jail%' => $jail]));
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl('clp_admin_fail2ban'));
    }
}
