<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class FtpAccountProvisioningService
{
    public function provisionForUser(User $user, string $plainPassword): array
    {
        $username = $this->resolveUniqueUsername($user);
        $homePath = $this->buildHomePath($username);

        if (PHP_OS_FAMILY === 'Windows') {
            File::ensureDirectoryExists($homePath);

            return $this->buildConfigPayload($username, '/');
        }

        $sudo = $this->sudoPrefix();

        if ($this->systemUserExists($username)) {
            throw new RuntimeException("FTP/system user '{$username}' already exists.");
        }

        $this->runCommand("{$sudo} mkdir -p " . escapeshellarg($homePath));
        $this->runCommand("{$sudo} useradd -M -d " . escapeshellarg($homePath) . ' -s ' . escapeshellarg($this->ftpShell()) . ' ' . escapeshellarg($username));

        try {
            $this->runChpasswd($sudo, $username, $plainPassword);
            $this->runCommand("{$sudo} chown -R " . escapeshellarg("{$username}:{$username}") . ' ' . escapeshellarg($homePath));
            $this->runCommand("{$sudo} chmod 750 " . escapeshellarg($homePath));
        } catch (\Throwable $exception) {
            $this->runCommand("{$sudo} userdel " . escapeshellarg($username), false);
            throw $exception;
        }

        return $this->buildConfigPayload($username, '/');
    }

    public function updateFtpPassword(User $user, string $plainPassword): void
    {
        if (! $user->ftp_username) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $this->runChpasswd($this->sudoPrefix(), $user->ftp_username, $plainPassword);
    }

    private function buildConfigPayload(string $username, string $homeDirectory): array
    {
        return [
            'home_directory' => $homeDirectory,
            'ftp_host' => (string) config('storage_manager.ftp.host', '127.0.0.1'),
            'ftp_port' => (int) config('storage_manager.ftp.port', 21),
            'ftp_username' => $username,
            'ftp_passive' => (bool) config('storage_manager.ftp.passive', true),
            'ftp_ssl' => (bool) config('storage_manager.ftp.ssl', false),
        ];
    }

    private function resolveUniqueUsername(User $user): string
    {
        $emailBase = '';
        if (str_contains((string) $user->email, '@')) {
            $emailBase = explode('@', (string) $user->email)[0];
        }

        $seed = $emailBase !== '' ? $emailBase : $user->name;
        $base = Str::of($seed)->lower()->replaceMatches('/[^a-z0-9._-]+/', '')->trim('._-')->limit(24, '');
        $base = $base !== '' ? (string) $base : 'storageuser';

        $candidate = $base;
        $counter = 1;

        while ($this->dbUsernameExists($candidate) || $this->systemUserExists($candidate)) {
            $suffix = (string) $counter;
            $trimmed = Str::limit($base, max(1, 24 - strlen($suffix)), '');
            $candidate = $trimmed . $suffix;
            $counter++;
        }

        return $candidate;
    }

    private function dbUsernameExists(string $username): bool
    {
        return User::where('ftp_username', $username)->exists();
    }

    private function buildHomePath(string $username): string
    {
        $basePath = (string) config('storage_manager.ftp.base_path', '/srv/ftp/users');
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR . '/\\');

        return $basePath . DIRECTORY_SEPARATOR . $username;
    }

    private function ftpShell(): string
    {
        return (string) config('storage_manager.ftp.user_shell', '/usr/sbin/nologin');
    }

    private function sudoPrefix(): string
    {
        return trim((string) config('storage_manager.ftp.sudo_prefix', 'sudo'));
    }

    private function systemUserExists(string $username): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $command = 'id -u ' . escapeshellarg($username) . ' >/dev/null 2>&1';
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    private function runCommand(string $command, bool $throwOnError = true): array
    {
        exec($command . ' 2>&1', $output, $exitCode);
        $outputText = trim(implode("\n", $output));

        if ($throwOnError && $exitCode !== 0) {
            throw new RuntimeException($outputText !== '' ? $outputText : "Command failed: {$command}");
        }

        return [$exitCode, $outputText];
    }

    private function runChpasswd(string $sudoPrefix, string $username, string $plainPassword): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(trim($sudoPrefix . ' chpasswd'), $descriptorSpec, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start chpasswd command.');
        }

        fwrite($pipes[0], $username . ':' . $plainPassword . PHP_EOL);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $message = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException($message !== '' ? $message : 'Failed to set FTP user password.');
        }
    }
}

