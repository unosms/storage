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

        if ($this->systemUserExists($username)) {
            throw new RuntimeException("FTP/system user '{$username}' already exists.");
        }

        $this->runCommand($this->sudoCommand($this->binMkdir()) . ' -p ' . escapeshellarg($homePath));
        $this->runCommand($this->sudoCommand($this->binUseradd()) . ' -M -d ' . escapeshellarg($homePath) . ' -s ' . escapeshellarg($this->ftpShell()) . ' ' . escapeshellarg($username));

        try {
            $this->runChpasswd($username, $plainPassword);
            $this->runCommand($this->sudoCommand($this->binChown()) . ' -R ' . escapeshellarg("{$username}:{$username}") . ' ' . escapeshellarg($homePath));
            $this->runCommand($this->sudoCommand($this->binChmod()) . ' 750 ' . escapeshellarg($homePath));
        } catch (\Throwable $exception) {
            $this->runCommand($this->sudoCommand($this->binUserdel()) . ' ' . escapeshellarg($username), false);
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

        $this->runChpasswd($user->ftp_username, $plainPassword);
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
        $base = Str::of($seed)->lower()->replaceMatches('/[^a-z0-9_-]+/', '')->trim('_-')->limit(24, '');
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

        $command = escapeshellarg($this->binId()) . ' -u ' . escapeshellarg($username) . ' >/dev/null 2>&1';
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

    private function runChpasswd(string $username, string $plainPassword): void
    {
        $tempFile = @tempnam(sys_get_temp_dir(), 'chpass_');
        if (! $tempFile) {
            throw new RuntimeException('Could not create temporary file for FTP password provisioning.');
        }

        try {
            @chmod($tempFile, 0600);
            $payload = $username . ':' . $plainPassword . PHP_EOL;

            if (@file_put_contents($tempFile, $payload, LOCK_EX) === false) {
                throw new RuntimeException('Could not write temporary password file for chpasswd.');
            }

            [$exitCode, $output] = $this->runCommand(
                $this->sudoCommand($this->binChpasswd()) . ' < ' . escapeshellarg($tempFile),
                false
            );

            if ($exitCode !== 0) {
                $message = $output !== '' ? $output : 'Failed to set FTP user password. Ensure sudo NOPASSWD is configured for chpasswd.';
                throw new RuntimeException($message);
            }
        } finally {
            @unlink($tempFile);
        }
    }

    private function sudoCommand(string $binaryPath): string
    {
        return trim($this->sudoPrefix() . ' ' . escapeshellarg($binaryPath));
    }

    private function binUseradd(): string
    {
        return (string) config('storage_manager.ftp.bin_useradd', '/usr/sbin/useradd');
    }

    private function binUserdel(): string
    {
        return (string) config('storage_manager.ftp.bin_userdel', '/usr/sbin/userdel');
    }

    private function binChpasswd(): string
    {
        return (string) config('storage_manager.ftp.bin_chpasswd', '/usr/sbin/chpasswd');
    }

    private function binMkdir(): string
    {
        return (string) config('storage_manager.ftp.bin_mkdir', '/bin/mkdir');
    }

    private function binChown(): string
    {
        return (string) config('storage_manager.ftp.bin_chown', '/bin/chown');
    }

    private function binChmod(): string
    {
        return (string) config('storage_manager.ftp.bin_chmod', '/bin/chmod');
    }

    private function binId(): string
    {
        return (string) config('storage_manager.ftp.bin_id', '/usr/bin/id');
    }
}
