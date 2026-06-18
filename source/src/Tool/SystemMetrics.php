<?php

namespace App\Tool;

class SystemMetrics
{
    /**
     * @return array{cpu_load: array{1: float, 5: float, 15: float}, cpu_count: int, memory: array{total_mb: int, used_mb: int, free_mb: int, available_mb: int, percent_used: float}, disks: array<int,array{mount: string, total_mb: int, used_mb: int, free_mb: int, percent_used: float}>, uptime_seconds: int, hostname: string, kernel: string}
     */
    public function snapshot(): array
    {
        return [
            'cpu_load'       => $this->loadAvg(),
            'cpu_count'      => $this->cpuCount(),
            'memory'         => $this->memory(),
            'disks'          => $this->disks(),
            'uptime_seconds' => $this->uptime(),
            'hostname'       => (string) @gethostname(),
            'kernel'         => php_uname('r'),
        ];
    }

    /**
     * @return array{1: float, 5: float, 15: float}
     */
    private function loadAvg(): array
    {
        $raw = @file_get_contents('/proc/loadavg');
        if (!is_string($raw)) {
            return ['1' => 0.0, '5' => 0.0, '15' => 0.0];
        }
        $parts = preg_split('/\s+/', trim($raw));
        return [
            '1'  => (float) ($parts[0] ?? 0),
            '5'  => (float) ($parts[1] ?? 0),
            '15' => (float) ($parts[2] ?? 0),
        ];
    }

    private function cpuCount(): int
    {
        $raw = @file_get_contents('/proc/cpuinfo');
        if (!is_string($raw)) {
            return 1;
        }
        return max(1, substr_count($raw, 'processor'));
    }

    /**
     * @return array{total_mb: int, used_mb: int, free_mb: int, available_mb: int, percent_used: float}
     */
    private function memory(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (!is_string($raw)) {
            return ['total_mb' => 0, 'used_mb' => 0, 'free_mb' => 0, 'available_mb' => 0, 'percent_used' => 0.0];
        }
        $m = [];
        preg_match('/MemTotal:\s+(\d+)/', $raw, $a);
        $m['total_kb'] = (int) ($a[1] ?? 0);
        preg_match('/MemFree:\s+(\d+)/', $raw, $b);
        $m['free_kb'] = (int) ($b[1] ?? 0);
        preg_match('/MemAvailable:\s+(\d+)/', $raw, $c);
        $m['available_kb'] = (int) ($c[1] ?? 0);
        $usedKb = $m['total_kb'] - $m['available_kb'];
        $pct = $m['total_kb'] > 0 ? round($usedKb * 100.0 / $m['total_kb'], 1) : 0.0;
        return [
            'total_mb'     => (int) round($m['total_kb']     / 1024),
            'used_mb'      => (int) round($usedKb            / 1024),
            'free_mb'      => (int) round($m['free_kb']      / 1024),
            'available_mb' => (int) round($m['available_kb'] / 1024),
            'percent_used' => $pct,
        ];
    }

    /**
     * @return array<int,array{mount: string, total_mb: int, used_mb: int, free_mb: int, percent_used: float}>
     */
    private function disks(): array
    {
        $out = @shell_exec('/bin/df -B1M --output=target,size,used,avail -x tmpfs -x devtmpfs -x squashfs 2>/dev/null');
        if (!is_string($out)) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 4) {
                continue;
            }
            [$mount, $totalMb, $usedMb, $availMb] = [$cols[0], (int) $cols[1], (int) $cols[2], (int) $cols[3]];
            if ($mount === '' || $totalMb <= 0) {
                continue;
            }
            $rows[] = [
                'mount'        => $mount,
                'total_mb'     => $totalMb,
                'used_mb'      => $usedMb,
                'free_mb'      => $availMb,
                'percent_used' => round($usedMb * 100.0 / $totalMb, 1),
            ];
        }
        return $rows;
    }

    private function uptime(): int
    {
        $raw = @file_get_contents('/proc/uptime');
        if (!is_string($raw)) {
            return 0;
        }
        $parts = preg_split('/\s+/', trim($raw));
        return (int) floor((float) ($parts[0] ?? 0));
    }
}
