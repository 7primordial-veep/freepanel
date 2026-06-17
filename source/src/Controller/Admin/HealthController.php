<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Service\Logger;
use App\Tool\SystemMetrics;

class HealthController extends Controller
{
    private SystemMetrics $metrics;

    public function __construct(SystemMetrics $metrics, TranslatorInterface $translator, Logger $logger)
    {
        $this->metrics = $metrics;
        parent::__construct($translator, $logger);
    }

    public function index(Request $request): Response
    {
        return $this->render('Admin/Health/index.html.twig', [
            'active'   => 'health',
            'snapshot' => $this->metrics->snapshot(),
        ]);
    }

    public function apiMetrics(Request $request): JsonResponse
    {
        return new JsonResponse($this->metrics->snapshot());
    }
}
