<?php

namespace App\Site\Nginx\Vhost\Processor;

class SecurityHeaders extends Processor
{
    protected string $placeholder = '{{security_headers}}';

    private const CSP_PRESETS = [
        'strict' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
        'relaxed' => "default-src 'self' https: data:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' data: https:",
        'report-only' => "default-src 'self'",
    ];

    private const PERMISSIONS_PRESETS = [
        'strict' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        'relaxed' => 'geolocation=(self), camera=(self), microphone=(self)',
    ];

    public function process(string $content) : string
    {
        $config = method_exists($this->site, 'getSecurityHeadersConfig')
            ? $this->site->getSecurityHeadersConfig()
            : [];

        $lines = [];

        // X-Frame-Options
        $frame = $config['frameOptions'] ?? 'SAMEORIGIN';
        if ($frame !== 'off') {
            $lines[] = sprintf('  add_header X-Frame-Options "%s" always;', $frame);
        }

        // X-Content-Type-Options
        if (($config['nosniff'] ?? true)) {
            $lines[] = '  add_header X-Content-Type-Options "nosniff" always;';
        }

        // Referrer-Policy
        $referrer = $config['referrerPolicy'] ?? 'strict-origin-when-cross-origin';
        if ($referrer !== 'off') {
            $lines[] = sprintf('  add_header Referrer-Policy "%s" always;', $referrer);
        }

        // HSTS
        if (!empty($config['hsts'])) {
            $maxAge = (int) ($config['hstsMaxAge'] ?? 31536000);
            $sub = !empty($config['hstsIncludeSubdomains']) ? '; includeSubDomains' : '';
            $preload = !empty($config['hstsPreload']) ? '; preload' : '';
            $lines[] = sprintf('  add_header Strict-Transport-Security "max-age=%d%s%s" always;', $maxAge, $sub, $preload);
        }

        // CSP
        $cspPreset = $config['cspPreset'] ?? 'off';
        if ($cspPreset !== 'off' && isset(self::CSP_PRESETS[$cspPreset])) {
            $headerName = $cspPreset === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $value = self::CSP_PRESETS[$cspPreset];
            $lines[] = sprintf('  add_header %s "%s" always;', $headerName, $this->sanitize($value));
        } elseif (!empty($config['cspCustom'])) {
            $lines[] = sprintf('  add_header Content-Security-Policy "%s" always;', $this->sanitize($config['cspCustom']));
        }

        // Permissions-Policy
        $permPreset = $config['permissionsPreset'] ?? 'off';
        if ($permPreset !== 'off' && isset(self::PERMISSIONS_PRESETS[$permPreset])) {
            $lines[] = sprintf('  add_header Permissions-Policy "%s" always;', self::PERMISSIONS_PRESETS[$permPreset]);
        }

        $value = empty($lines) ? '' : implode(PHP_EOL, $lines);
        return $this->replace($value, $content);
    }

    private function sanitize(string $value) : string
    {
        return str_replace(['"', "\r", "\n"], ['\\"', '', ' '], $value);
    }
}
