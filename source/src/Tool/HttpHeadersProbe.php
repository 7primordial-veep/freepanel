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

        // SSRF guard: resolve the host to every A/AAAA and refuse if ANY address
        // sits in a private/loopback/link-local/CGNAT range. We refuse on the
        // any-private rule (not majority) so a hostile DNS record that maps a
        // public name to a mix of public + 127.0.0.1 still gets blocked.
        $sshError = $this->ssrfGuard((string) $parsed['host']);
        if (null !== $sshError) {
            $result['error'] = $sshError;
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
            // Redirects disabled — every redirect target would need a fresh SSRF
            // check, and the tester's job is to inspect this URL's headers, not
            // the headers of whatever it redirects to. The first Location: header
            // is still surfaced in the response.
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
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

    /**
     * Returns null if the host is safe to fetch, or a human-readable reason string
     * if any resolved IP sits in a private / loopback / link-local / CGNAT range.
     * We refuse on ANY match (not majority) so a hostile DNS record that returns
     * both a public and a private IP is still blocked.
     */
    private function ssrfGuard(string $host): ?string
    {
        $host = trim($host, '[]'); // bracketed IPv6 literal
        if ('' === $host) {
            return 'empty host';
        }
        // Collect candidate IPs: literal, or DNS A/AAAA lookups.
        $ips = [];
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = @gethostbynamel($host); // IPv4
            if (is_array($a)) {
                $ips = array_merge($ips, $a);
            }
            // IPv6
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        if (0 === count($ips)) {
            return sprintf('could not resolve host %s', $host);
        }
        foreach ($ips as $ip) {
            if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($this->isPrivateIp($ip)) {
                return sprintf('refusing to fetch — %s resolves to private/loopback/link-local address %s', $host, $ip);
            }
        }
        return null;
    }

    private function isPrivateIp(string $ip): bool
    {
        // PHP's reserved+private flags cover RFC1918, loopback, link-local, etc.
        // The combined filter blocks: 0.0.0.0/8, 10.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16,
        // 172.16.0.0/12, 192.0.0.0/24 (mostly), 192.168.0.0/16, 224.0.0.0/4, 240.0.0.0/4,
        // ::1/128, ::ffff:0:0/96 (IPv4-mapped), fc00::/7, fe80::/10, etc.
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        // Catch 100.64.0.0/10 (CGNAT) explicitly — PHP's filter doesn't.
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if (false !== $long && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return true;
            }
        }
        return false;
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
