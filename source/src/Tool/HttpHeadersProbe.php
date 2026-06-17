<?php

namespace App\Tool;

class HttpHeadersProbe
{
    private const SECURITY_HEADERS = [
        ['name' => 'Strict-Transport-Security', 'key' => 'strict-transport-security'],
        ['name' => 'Content-Security-Policy', 'key' => 'content-security-policy'],
        ['name' => 'X-Frame-Options / CSP frame-ancestors', 'key' => '__frame_options__'],
        ['name' => 'X-Content-Type-Options: nosniff', 'key' => '__nosniff__'],
        ['name' => 'Referrer-Policy', 'key' => 'referrer-policy'],
        ['name' => 'Permissions-Policy', 'key' => 'permissions-policy'],
    ];

    public function probe(string $url): array
    {
        $url = trim($url);
        $result = [
            'url' => $url,
            'status' => null,
            'protocol' => '',
            'headers' => [],
            'securityChecklist' => [],
            'error' => null,
        ];

        if ('' === $url) {
            $result['error'] = 'URL is empty.';
            $result['securityChecklist'] = $this->buildChecklist([]);
            return $result;
        }
        if (0 !== stripos($url, 'http://') && 0 !== stripos($url, 'https://')) {
            $result['error'] = 'URL must start with http:// or https://';
            $result['securityChecklist'] = $this->buildChecklist([]);
            return $result;
        }
        $parsed = @parse_url($url);
        if (false === $parsed || !is_array($parsed) || empty($parsed['host'])) {
            $result['error'] = 'URL could not be parsed (missing host).';
            $result['securityChecklist'] = $this->buildChecklist([]);
            return $result;
        }

        if (false === function_exists('curl_init')) {
            $result['error'] = 'PHP curl extension is not available.';
            $result['securityChecklist'] = $this->buildChecklist([]);
            return $result;
        }

        $versions = [];
        if (defined('CURL_HTTP_VERSION_3')) {
            $versions[] = constant('CURL_HTTP_VERSION_3');
        } else {
            $versions[] = 30;
        }
        $versions[] = 3; // CURL_HTTP_VERSION_2_0
        $versions[] = 2; // CURL_HTTP_VERSION_1_1

        $lastError = null;
        $lastErrno = 0;
        foreach ($versions as $httpVersion) {
            $ch = curl_init();
            if (false === $ch) {
                $result['error'] = 'curl_init failed.';
                $result['securityChecklist'] = $this->buildChecklist([]);
                return $result;
            }
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, 'CloudPanel-HeadersTester/1.0');
            @curl_setopt($ch, CURLOPT_HTTP_VERSION, $httpVersion);

            $raw = curl_exec($ch);
            if (false === $raw) {
                $lastError = curl_error($ch);
                $lastErrno = curl_errno($ch);
                curl_close($ch);
                continue;
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $actualVersion = (int) curl_getinfo($ch, CURLINFO_HTTP_VERSION);
            $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $rawString = is_string($raw) ? $raw : '';
            $headerBlock = $headerSize > 0 ? substr($rawString, 0, $headerSize) : $rawString;
            $headers = $this->parseHeaders($headerBlock);

            $result['url'] = '' !== $effectiveUrl ? $effectiveUrl : $url;
            $result['status'] = $status > 0 ? $status : null;
            $result['protocol'] = $this->mapProtocol($actualVersion);
            $result['headers'] = $headers;
            $result['securityChecklist'] = $this->buildChecklist($headers);
            $result['error'] = null;
            return $result;
        }

        $result['error'] = sprintf('curl error (%d): %s', $lastErrno, $lastError ?? 'request failed');
        $result['securityChecklist'] = $this->buildChecklist([]);
        return $result;
    }

    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $blocks = preg_split("/\r?\n\r?\n/", trim($headerBlock));
        if (!is_array($blocks) || 0 === count($blocks)) {
            return $headers;
        }
        $last = end($blocks);
        if (!is_string($last)) {
            return $headers;
        }
        $lines = preg_split("/\r?\n/", $last);
        if (!is_array($lines)) {
            return $headers;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if (0 === stripos($line, 'HTTP/')) {
                continue;
            }
            $pos = strpos($line, ':');
            if (false === $pos) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ('' === $name) {
                continue;
            }
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private function mapProtocol(int $version): string
    {
        switch ($version) {
            case 30:
                return 'HTTP/3';
            case 31:
                return 'HTTP/3';
            case 3:
                return 'HTTP/2';
            case 2:
                return 'HTTP/1.1';
            case 1:
                return 'HTTP/1.0';
            default:
                return 'unknown';
        }
    }

    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
        }
        return null;
    }

    private function buildChecklist(array $headers): array
    {
        $checklist = [];

        $hsts = $this->findHeader($headers, 'Strict-Transport-Security');
        $checklist[] = [
            'name' => 'Strict-Transport-Security (HSTS)',
            'present' => null !== $hsts,
            'value' => $hsts,
        ];

        $csp = $this->findHeader($headers, 'Content-Security-Policy');
        $checklist[] = [
            'name' => 'Content-Security-Policy',
            'present' => null !== $csp,
            'value' => $csp,
        ];

        $xfo = $this->findHeader($headers, 'X-Frame-Options');
        $frameAncestors = (null !== $csp && false !== stripos($csp, 'frame-ancestors'));
        $frameValue = null;
        if (null !== $xfo) {
            $frameValue = 'X-Frame-Options: ' . $xfo;
        } elseif ($frameAncestors) {
            $frameValue = 'CSP frame-ancestors directive present';
        }
        $checklist[] = [
            'name' => 'X-Frame-Options or CSP frame-ancestors',
            'present' => (null !== $xfo) || $frameAncestors,
            'value' => $frameValue,
        ];

        $xcto = $this->findHeader($headers, 'X-Content-Type-Options');
        $nosniff = (null !== $xcto && false !== stripos($xcto, 'nosniff'));
        $checklist[] = [
            'name' => 'X-Content-Type-Options: nosniff',
            'present' => $nosniff,
            'value' => $xcto,
        ];

        $referrer = $this->findHeader($headers, 'Referrer-Policy');
        $checklist[] = [
            'name' => 'Referrer-Policy',
            'present' => null !== $referrer,
            'value' => $referrer,
        ];

        $perms = $this->findHeader($headers, 'Permissions-Policy');
        $checklist[] = [
            'name' => 'Permissions-Policy',
            'present' => null !== $perms,
            'value' => $perms,
        ];

        return $checklist;
    }
}
