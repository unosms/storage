<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\TransferLog;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = TransferLog::with('user')->latest('started_at')->latest('id');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $logs = $query->paginate(20);

        return view('transfers.index', [
            'logs' => $logs,
            'quotaUsedGb' => round($user->used_space_bytes / 1024 / 1024 / 1024, 2),
            'quotaTotalGb' => $user->quota_mb > 0 ? round($user->quota_mb / 1024, 2) : null,
        ]);
    }

    public function upload(Request $request)
    {
        $user = $request->user();

        if (! $user->can_upload && ! $user->isAdmin()) {
            return back()->withErrors(['upload' => 'You do not have upload permission.']);
        }

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $request->validate([
            'file' => ['required', 'file'],
            'remote_subdir' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $fileSize = (int) ($file?->getSize() ?? 0);

        if ($fileSize <= 0) {
            return back()->withErrors(['upload' => 'Uploaded file is empty or unreadable.']);
        }

        if ($user->quota_mb > 0 && ($user->used_space_bytes + $fileSize) > $user->quotaBytes()) {
            return back()->withErrors(['upload' => 'Quota exceeded for this user.']);
        }

        if (! function_exists('ftp_connect')) {
            return back()->withErrors(['upload' => 'PHP FTP extension is not enabled on this server.']);
        }

        if (! $user->ftp_host || ! $user->ftp_username || ! $user->ftp_password) {
            return back()->withErrors(['upload' => 'FTP settings are not complete. Ask admin to set host/username/password.']);
        }

        $safeName = $this->sanitizeFilename($file->getClientOriginalName());
        $filename = now()->format('Ymd_His') . '_' . $safeName;

        $baseDir = trim((string) $user->home_directory, '/');
        $subDir = trim((string) $request->string('remote_subdir')->toString(), '/');

        $targetDir = collect([$baseDir, $subDir])->filter(fn ($segment) => $segment !== '')->implode('/');
        $ftpPath = ($targetDir !== '' ? $targetDir . '/' : '') . $filename;

        $log = TransferLog::create([
            'user_id' => $user->id,
            'direction' => 'upload',
            'status' => 'in_progress',
            'original_name' => $file->getClientOriginalName(),
            'filename' => $filename,
            'ftp_path' => $ftpPath,
            'size_bytes' => $fileSize,
            'started_at' => now(),
            'client_ip' => $request->ip(),
        ]);

        $startedAt = microtime(true);
        $connection = null;

        try {
            $connection = $user->ftp_ssl
                ? @ftp_ssl_connect($user->ftp_host, (int) $user->ftp_port, 30)
                : @ftp_connect($user->ftp_host, (int) $user->ftp_port, 30);

            if (! $connection) {
                throw new Exception('Could not connect to FTP host.');
            }

            if (! @ftp_login($connection, $user->ftp_username, $user->ftp_password)) {
                throw new Exception($this->ftpLoginFailedMessage($user));
            }

            @ftp_pasv($connection, (bool) $user->ftp_passive);

            if ($targetDir !== '') {
                $this->ensureFtpDirectory($connection, $targetDir);
            }

            $uploaded = @ftp_put($connection, $ftpPath, $file->getRealPath(), FTP_BINARY);

            if (! $uploaded) {
                throw new Exception('FTP upload failed.');
            }

            $seconds = max(0.01, microtime(true) - $startedAt);
            if ($user->speed_limit_kbps && $user->speed_limit_kbps > 0) {
                $minimumSeconds = max(0.01, ($fileSize * 8 / 1024) / $user->speed_limit_kbps);
                if ($seconds < $minimumSeconds) {
                    usleep((int) (($minimumSeconds - $seconds) * 1000000));
                    $seconds = $minimumSeconds;
                }
            }

            $speedKbps = round(($fileSize * 8 / 1024) / $seconds, 2);

            DB::transaction(function () use ($log, $user, $fileSize, $speedKbps) {
                $log->update([
                    'status' => 'completed',
                    'speed_kbps' => $speedKbps,
                    'finished_at' => now(),
                ]);

                $user->increment('used_space_bytes', $fileSize);
            });

            return back()->with('status', 'Upload completed to FTP: ' . $ftpPath);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => Str::limit($exception->getMessage(), 500),
            ]);

            return back()->withErrors(['upload' => $exception->getMessage()]);
        } finally {
            if ($connection) {
                @ftp_close($connection);
            }
        }
    }

    private function ensureFtpDirectory($connection, string $path): void
    {
        $segments = explode('/', trim($path, '/'));
        $currentPath = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $currentPath .= '/' . $segment;
            @ftp_mkdir($connection, $currentPath);
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'file.bin';
        $filename = trim($filename, '_');

        return $filename !== '' ? $filename : 'file.bin';
    }

    private function ftpLoginFailedMessage(User $user): string
    {
        $message = "FTP login failed for '{$user->ftp_username}' at {$user->ftp_host}:{$user->ftp_port}.";

        if (PHP_OS_FAMILY !== 'Linux') {
            return $message;
        }

        if (function_exists('posix_getpwnam')) {
            $account = @posix_getpwnam((string) $user->ftp_username);
            if (! is_array($account)) {
                return $message . ' System user not found.';
            }

            $home = (string) ($account['dir'] ?? '');
            if ($home !== '' && ! is_dir($home)) {
                $message .= " Home directory '{$home}' does not exist.";
            }

            $shell = (string) ($account['shell'] ?? '');
            if ($shell !== '' && ! $this->shellIsAllowed($shell)) {
                $message .= " Shell '{$shell}' is not listed in /etc/shells.";
            }
        }

        return $message;
    }

    private function shellIsAllowed(string $shell): bool
    {
        $shellsFile = '/etc/shells';
        if (! is_file($shellsFile) || ! is_readable($shellsFile)) {
            return true;
        }

        $shells = @file($shellsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($shells)) {
            return true;
        }

        return in_array(trim($shell), array_map('trim', $shells), true);
    }
}
