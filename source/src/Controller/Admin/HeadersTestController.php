<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Service\Logger;
use App\Tool\HttpHeadersProbe;

class HeadersTestController extends Controller
{
    private HttpHeadersProbe $probe;

    public function __construct(HttpHeadersProbe $probe, TranslatorInterface $translator, Logger $logger)
    {
        $this->probe = $probe;
        parent::__construct($translator, $logger);
    }

    public function index(Request $request): Response
    {
        return $this->render('Admin/Tools/headers-test.html.twig', [
            'result' => null,
            'url' => '',
            'active' => 'tools-headers',
        ]);
    }

    public function probe(Request $request): Response
    {
        $this->checkCsrfToken($request, 'headers-test-probe');
        $url = trim((string) $request->request->get('url', ''));
        $result = null;
        try {
            $result = $this->probe->probe($url);
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $result = [
                'url' => $url,
                'status' => null,
                'protocol' => '',
                'headers' => [],
                'securityChecklist' => [],
                'error' => $e->getMessage(),
            ];
        }
        return $this->render('Admin/Tools/headers-test.html.twig', [
            'result' => $result,
            'url' => $url,
            'active' => 'tools-headers',
        ]);
    }
}
