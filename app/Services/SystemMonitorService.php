<?php

namespace App\Services;

class SystemMonitorService
{
    public function snapshot(): array
    {
        $ram = $this->ramStats();
        $disk = $this->diskStats();

        return [
            'cpu_percent' => $this->cpuPercent(),
            'ram_percent' => $ram['percent'],
            'ram_used_gb' => $ram['used_gb'],
            'ram_total_gb' => $ram['total_gb'],
            'disk_percent' => $disk['percent'],
            'disk_used_gb' => $disk['used_gb'],
            'disk_total_gb' => $disk['total_gb'],
        ];
    }

    private function cpuPercent(): ?float
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = $this->shell('wmic cpu get loadpercentage /value');
            if ($output && preg_match('/LoadPercentage=(\d+)/i', $output, $matches)) {
                return (float) $matches[1];
            }

            return null;
        }

        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        if (! isset($load[0])) {
            return null;
        }

        $cores = $this->cpuCores();
        if ($cores < 1) {
            return null;
        }

        $percent = ($load[0] / $cores) * 100;

        return max(0, min(100, round($percent, 1)));
    }

    private function cpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $processors = getenv('NUMBER_OF_PROCESSORS');

            return $processors ? (int) $processors : 1;
        }

        $nproc = $this->shell('nproc 2>/dev/null');
        if ($nproc) {
            $count = (int) trim($nproc);
            if ($count > 0) {
                return $count;
            }
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuInfo) {
            return max(1, substr_count($cpuInfo, 'processor'));
        }

        return 1;
    }

    private function ramStats(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = $this->shell('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value');

            if ($output) {
                preg_match('/FreePhysicalMemory=(\d+)/i', $output, $free);
                preg_match('/TotalVisibleMemorySize=(\d+)/i', $output, $total);

                if (! empty($free[1]) && ! empty($total[1])) {
                    $freeKb = (float) $free[1];
                    $totalKb = (float) $total[1];
                    $usedKb = max(0, $totalKb - $freeKb);

                    return [
                        'total_gb' => round($totalKb / 1024 / 1024, 2),
                        'used_gb' => round($usedKb / 1024 / 1024, 2),
                        'percent' => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : null,
                    ];
                }
            }

            return ['total_gb' => null, 'used_gb' => null, 'percent' => null];
        }

        $meminfo = @file('/proc/meminfo');
        if (! $meminfo) {
            return ['total_gb' => null, 'used_gb' => null, 'percent' => null];
        }

        $data = [];
        foreach ($meminfo as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $data[$matches[1]] = (int) $matches[2];
            }
        }

        if (! isset($data['MemTotal'])) {
            return ['total_gb' => null, 'used_gb' => null, 'percent' => null];
        }

        $totalKb = (float) $data['MemTotal'];
        $availableKb = (float) ($data['MemAvailable'] ?? $data['MemFree'] ?? 0);
        $usedKb = max(0, $totalKb - $availableKb);

        return [
            'total_gb' => round($totalKb / 1024 / 1024, 2),
            'used_gb' => round($usedKb / 1024 / 1024, 2),
            'percent' => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : null,
        ];
    }

    private function diskStats(): array
    {
        $path = DIRECTORY_SEPARATOR === '\\' ? 'C:\\' : '/';

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! $total || ! $free) {
            return ['total_gb' => null, 'used_gb' => null, 'percent' => null];
        }

        $used = max(0, $total - $free);

        return [
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_gb' => round($used / 1024 / 1024 / 1024, 2),
            'percent' => round(($used / $total) * 100, 1),
        ];
    }

    private function shell(string $command): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $result = @shell_exec($command);

        if (! is_string($result)) {
            return null;
        }

        $result = trim($result);

        return $result === '' ? null : $result;
    }
}
