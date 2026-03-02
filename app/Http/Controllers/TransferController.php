<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\TransferLog;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $this->reconcileInProgressTransfers($user);

        $query = TransferLog::with('user')->latest('started_at')->latest('id');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $logs = $query->paginate(20)->withQueryString();

        $currentDir = '';
        $parentDir = null;
        $directories = [];
        $files = [];
        $browserError = null;

        try {
            $currentDir = $this->normalizeRelativePath((string) $request->query('dir', ''));
            [$directories, $files, $parentDir] = $this->listRemoteEntries($user, $currentDir);
        } catch (Throwable $exception) {
            $browserError = $exception->getMessage();
        }

        return view('transfers.index', [
            'logs' => $logs,
            'quotaUsedGb' => round($user->used_space_bytes / 1024 / 1024 / 1024, 2),
            'quotaTotalGb' => $user->quota_mb > 0 ? round($user->quota_mb / 1024, 2) : null,
            'currentDir' => $currentDir,
            'parentDir' => $parentDir,
            'directories' => $directories,
            'files' => $files,
            'browserError' => $browserError,
        ]);
    }

    public function upload(Request $request)
    {
        $user = $request->user();
        $expectsJson = $request->expectsJson() || $request->ajax();

        if (! $user->can_upload && ! $user->isAdmin()) {
            return $this->errorResponse($request, 'You do not have upload permission.', 403);
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
            return $this->errorResponse($request, 'Uploaded file is empty or unreadable.');
        }

        if ($user->quota_mb > 0 && ($user->used_space_bytes + $fileSize) > $user->quotaBytes()) {
            return $this->errorResponse($request, 'Quota exceeded for this user.');
        }

        if (! function_exists('ftp_connect')) {
            return $this->errorResponse($request, 'PHP FTP extension is not enabled on this server.');
        }

        $safeName = $this->sanitizeFilename($file->getClientOriginalName());

        try {
            $targetDir = $this->normalizeRelativePath((string) $request->string('remote_subdir')->toString());
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage());
        }

        $relativePath = $this->joinRelativePath($targetDir, $safeName);
        $absoluteRemotePath = $this->absoluteFtpPath($user, $relativePath);
        $absoluteTargetDir = $this->absoluteFtpPath($user, $targetDir);

        $log = TransferLog::create([
            'user_id' => $user->id,
            'direction' => 'upload',
            'status' => 'in_progress',
            'original_name' => $file->getClientOriginalName(),
            'filename' => $safeName,
            'ftp_path' => $relativePath,
            'size_bytes' => $fileSize,
            'started_at' => now(),
            'client_ip' => $request->ip(),
        ]);

        try {
            $result = $this->uploadFileWithResume(
                $user,
                (string) $file->getRealPath(),
                $absoluteRemotePath,
                $fileSize,
                $absoluteTargetDir
            );

            $existingBytes = min($result['existing_start'], $fileSize);
            $bytesTransferred = max(0, $fileSize - $existingBytes);
            $seconds = $result['seconds'];

            if ($user->speed_limit_kbps && $user->speed_limit_kbps > 0 && $bytesTransferred > 0) {
                $minimumSeconds = max(0.01, ($bytesTransferred * 8 / 1024) / $user->speed_limit_kbps);
                if ($seconds < $minimumSeconds) {
                    usleep((int) (($minimumSeconds - $seconds) * 1000000));
                    $seconds = $minimumSeconds;
                }
            }

            $speedKbps = $bytesTransferred > 0
                ? round(($bytesTransferred * 8 / 1024) / max(0.01, $seconds), 2)
                : 0.00;

            DB::transaction(function () use ($log, $user, $bytesTransferred, $speedKbps) {
                $log->update([
                    'status' => 'completed',
                    'speed_kbps' => $speedKbps,
                    'finished_at' => now(),
                ]);

                if ($bytesTransferred > 0) {
                    $user->increment('used_space_bytes', $bytesTransferred);
                }
            });

            $message = 'Upload completed to FTP: ' . $relativePath;
            if ($existingBytes > 0) {
                $message .= ' (resumed from partial upload)';
            }

            if ($expectsJson) {
                return response()->json([
                    'ok' => true,
                    'message' => $message,
                    'path' => $relativePath,
                    'speed_kbps' => $speedKbps,
                    'redirect_url' => route('transfers.index', ['dir' => $targetDir]),
                ]);
            }

            return redirect()
                ->route('transfers.index', ['dir' => $targetDir])
                ->with('status', $message);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => Str::limit($exception->getMessage(), 500),
            ]);

            return $this->errorResponse($request, $exception->getMessage());
        }
    }

    public function createFolder(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'folder_name' => ['required', 'string', 'max:120'],
            'current_dir' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $currentDir = $this->normalizeRelativePath((string) $request->input('current_dir', ''));
            $folderName = $this->sanitizeFolderName((string) $request->input('folder_name'));
            $newRelativePath = $this->joinRelativePath($currentDir, $folderName);
        } catch (Throwable $exception) {
            return redirect()
                ->route('transfers.index')
                ->withErrors(['upload' => 'Create folder failed: ' . $exception->getMessage()]);
        }

        $connection = null;

        try {
            $connection = $this->openFtpConnection($user);
            $this->ensureFtpDirectory($connection, $this->absoluteFtpPath($user, $newRelativePath));

            return redirect()
                ->route('transfers.index', ['dir' => $currentDir])
                ->with('status', "Folder '{$folderName}' created.");
        } catch (Throwable $exception) {
            return redirect()
                ->route('transfers.index', ['dir' => $currentDir])
                ->withErrors(['upload' => 'Create folder failed: ' . $exception->getMessage()]);
        } finally {
            if ($connection) {
                @ftp_close($connection);
            }
        }
    }

    public function download(Request $request): BinaryFileResponse
    {
        $request->validate([
            'path' => ['nullable', 'string', 'max:1024'],
            'log' => ['nullable', 'integer'],
        ]);

        $currentUser = $request->user();
        $ftpUser = $currentUser;

        try {
            $relativePath = $this->normalizeRelativePath((string) $request->query('path', ''));
        } catch (Throwable $exception) {
            abort(404, $exception->getMessage());
        }

        $downloadName = $this->sanitizeFilename((string) $request->query('name', basename($relativePath) ?: 'download.bin'));

        if ($request->filled('log')) {
            $log = TransferLog::with('user')->findOrFail((int) $request->query('log'));
            if (! $currentUser->isAdmin() && $log->user_id !== $currentUser->id) {
                abort(403);
            }

            $ftpUser = $log->user ?? $currentUser;
            $relativePath = $this->normalizeStoredLogPath($ftpUser, (string) $log->ftp_path);
            $downloadName = $this->sanitizeFilename($log->original_name ?: basename($relativePath));
        }

        if ($relativePath === '') {
            abort(404, 'File path is required.');
        }

        $absolutePath = $this->absoluteFtpPath($ftpUser, $relativePath);
        $tmpDir = storage_path('app/tmp-downloads');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $tmpFile = tempnam($tmpDir, 'ftpdl_');
        if (! $tmpFile) {
            abort(500, 'Could not allocate temporary file.');
        }

        $connection = null;

        try {
            $connection = $this->openFtpConnection($ftpUser);
            if (! @ftp_get($connection, $tmpFile, $absolutePath, FTP_BINARY)) {
                throw new Exception('Could not download requested file from FTP.');
            }
        } catch (Throwable $exception) {
            @unlink($tmpFile);
            abort(404, $exception->getMessage());
        } finally {
            if ($connection) {
                @ftp_close($connection);
            }
        }

        return response()->download($tmpFile, $downloadName)->deleteFileAfterSend(true);
    }

    private function uploadFileWithResume(User $user, string $localPath, string $absoluteRemotePath, int $fileSize, string $absoluteTargetDir): array
    {
        $maxAttempts = 3;
        $attempt = 0;
        $lastError = 'FTP upload failed.';
        $startedAt = microtime(true);
        $existingStart = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $connection = null;
            $stream = null;

            try {
                $connection = $this->openFtpConnection($user);

                if ($absoluteTargetDir !== '/') {
                    $this->ensureFtpDirectory($connection, $absoluteTargetDir);
                }

                $remoteSize = (int) @ftp_size($connection, $absoluteRemotePath);
                if ($remoteSize < 0) {
                    $remoteSize = 0;
                }

                if ($attempt === 1) {
                    $existingStart = min($remoteSize, $fileSize);
                }

                if ($remoteSize > $fileSize) {
                    @ftp_delete($connection, $absoluteRemotePath);
                    $remoteSize = 0;
                    if ($attempt === 1) {
                        $existingStart = 0;
                    }
                }

                if ($remoteSize === $fileSize) {
                    return [
                        'seconds' => max(0.01, microtime(true) - $startedAt),
                        'existing_start' => $existingStart,
                    ];
                }

                $stream = fopen($localPath, 'rb');
                if (! is_resource($stream)) {
                    throw new Exception('Uploaded file is not readable from temporary storage.');
                }

                if ($remoteSize > 0 && fseek($stream, $remoteSize) !== 0) {
                    throw new Exception('Could not seek local file to resume position.');
                }

                $state = @ftp_nb_fput($connection, $absoluteRemotePath, $stream, FTP_BINARY, $remoteSize);
                while ($state === FTP_MOREDATA) {
                    $state = @ftp_nb_continue($connection);
                }

                if ($state === FTP_FINISHED) {
                    return [
                        'seconds' => max(0.01, microtime(true) - $startedAt),
                        'existing_start' => $existingStart,
                    ];
                }

                $lastError = 'FTP upload was interrupted. Retrying...';
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($connection) {
                    @ftp_close($connection);
                }
            }
        }

        throw new Exception($lastError);
    }

    private function listRemoteEntries(User $user, string $currentDir): array
    {
        $connection = null;

        try {
            $connection = $this->openFtpConnection($user);
            $absoluteDir = $this->absoluteFtpPath($user, $currentDir);
            $rawList = @ftp_rawlist($connection, $absoluteDir);

            if ($rawList === false) {
                throw new Exception('Could not read remote folder list.');
            }

            $directories = [];
            $files = [];

            foreach ($rawList as $line) {
                $entry = $this->parseRawListLine((string) $line);
                if (! $entry) {
                    continue;
                }

                $entryRelativePath = $this->joinRelativePath($currentDir, $entry['name']);

                if ($entry['is_dir']) {
                    $directories[] = [
                        'name' => $entry['name'],
                        'relative_path' => $entryRelativePath,
                    ];
                } else {
                    $files[] = [
                        'name' => $entry['name'],
                        'relative_path' => $entryRelativePath,
                        'size_bytes' => $entry['size'],
                    ];
                }
            }

            usort($directories, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            $parentDir = null;
            if ($currentDir !== '') {
                $parentDir = str_contains($currentDir, '/')
                    ? Str::beforeLast($currentDir, '/')
                    : '';
            }

            return [$directories, $files, $parentDir];
        } finally {
            if ($connection) {
                @ftp_close($connection);
            }
        }
    }

    private function parseRawListLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with(strtolower($line), 'total ')) {
            return null;
        }

        if (preg_match('/^\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}[AP]M\s+(<DIR>|\d+)\s+(.+)$/i', $line, $matches) === 1) {
            $name = trim($matches[2]);
            if ($name === '.' || $name === '..') {
                return null;
            }

            return [
                'name' => $name,
                'is_dir' => strtoupper($matches[1]) === '<DIR>',
                'size' => strtoupper($matches[1]) === '<DIR>' ? 0 : (int) $matches[1],
            ];
        }

        $parts = preg_split('/\s+/', $line, 9);
        if (! is_array($parts) || count($parts) < 9) {
            return null;
        }

        $name = trim($parts[8]);
        if (str_contains($name, ' -> ')) {
            $name = trim((string) Str::before($name, ' -> '));
        }

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        return [
            'name' => $name,
            'is_dir' => str_starts_with($parts[0], 'd'),
            'size' => (int) $parts[4],
        ];
    }

    private function openFtpConnection(User $user)
    {
        $this->assertFtpConfigured($user);

        $connection = $user->ftp_ssl
            ? @ftp_ssl_connect($user->ftp_host, (int) $user->ftp_port, 30)
            : @ftp_connect($user->ftp_host, (int) $user->ftp_port, 30);

        if (! $connection) {
            throw new Exception('Could not connect to FTP host.');
        }

        if (! @ftp_login($connection, $user->ftp_username, $user->ftp_password)) {
            @ftp_close($connection);
            throw new Exception($this->ftpLoginFailedMessage($user));
        }

        @ftp_pasv($connection, (bool) $user->ftp_passive);

        return $connection;
    }

    private function assertFtpConfigured(User $user): void
    {
        if (! function_exists('ftp_connect')) {
            throw new Exception('PHP FTP extension is not enabled on this server.');
        }

        if (! $user->ftp_host || ! $user->ftp_username || ! $user->ftp_password) {
            throw new Exception('FTP settings are not complete. Ask admin to set host/username/password.');
        }
    }

    private function ensureFtpDirectory($connection, string $absolutePath): void
    {
        $segments = explode('/', trim($absolutePath, '/'));
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

    private function sanitizeFolderName(string $folderName): string
    {
        $folderName = trim(str_replace(['\\', '/'], '', $folderName));
        $folderName = preg_replace('/[^\pL\pN._\- ]/u', '', $folderName) ?? '';

        if ($folderName === '' || $folderName === '.' || $folderName === '..') {
            throw new Exception('Invalid folder name.');
        }

        return $folderName;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $cleanSegments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new Exception('Invalid path segment.');
            }
            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments);
    }

    private function joinRelativePath(string $base, string $segment): string
    {
        $base = trim($base, '/');
        $segment = trim($segment, '/');

        if ($base === '') {
            return $segment;
        }

        if ($segment === '') {
            return $base;
        }

        return $base . '/' . $segment;
    }

    private function absoluteFtpPath(User $user, string $relativePath = ''): string
    {
        $home = $this->normalizeRelativePath((string) ($user->home_directory ?? ''));
        $relativePath = $this->normalizeRelativePath($relativePath);
        $joined = $this->joinRelativePath($home, $relativePath);

        return '/' . ltrim($joined, '/');
    }

    private function normalizeStoredLogPath(User $user, string $storedPath): string
    {
        $storedPath = str_replace('\\', '/', trim($storedPath));
        if ($storedPath === '') {
            return '';
        }

        $home = $this->normalizeRelativePath((string) ($user->home_directory ?? ''));

        if (str_starts_with($storedPath, '/')) {
            $absolute = $this->normalizeRelativePath(ltrim($storedPath, '/'));

            if ($home !== '' && ($absolute === $home || str_starts_with($absolute, $home . '/'))) {
                return ltrim((string) Str::after($absolute, $home), '/');
            }

            return $absolute;
        }

        return $this->normalizeRelativePath($storedPath);
    }

    private function errorResponse(Request $request, string $message, int $status = 422)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['upload' => $message]);
    }

    private function reconcileInProgressTransfers(User $viewer): void
    {
        $query = TransferLog::with('user')
            ->where('status', 'in_progress')
            ->latest('started_at')
            ->limit(25);

        if (! $viewer->isAdmin()) {
            $query->where('user_id', $viewer->id);
        }

        $logs = $query->get();

        foreach ($logs as $log) {
            $ftpUser = $log->user;
            if (! $ftpUser) {
                continue;
            }

            $connection = null;

            try {
                $connection = $this->openFtpConnection($ftpUser);
                $relativePath = $this->normalizeStoredLogPath($ftpUser, (string) $log->ftp_path);
                if ($relativePath === '') {
                    continue;
                }

                $absolutePath = $this->absoluteFtpPath($ftpUser, $relativePath);
                $remoteSize = (int) @ftp_size($connection, $absolutePath);

                if ($remoteSize < 0 || $remoteSize < (int) $log->size_bytes) {
                    continue;
                }

                $startedAt = $log->started_at ?? now();
                $seconds = max(1, now()->diffInSeconds($startedAt));
                $speedKbps = round((((int) $log->size_bytes) * 8 / 1024) / $seconds, 2);

                DB::transaction(function () use ($log, $ftpUser, $speedKbps) {
                    $freshLog = TransferLog::lockForUpdate()->find($log->id);
                    if (! $freshLog || $freshLog->status !== 'in_progress') {
                        return;
                    }

                    $freshLog->update([
                        'status' => 'completed',
                        'speed_kbps' => $speedKbps,
                        'finished_at' => now(),
                        'message' => 'Recovered from pending state after upload check.',
                    ]);

                    $ftpUser->increment('used_space_bytes', (int) $freshLog->size_bytes);
                });
            } catch (Throwable $exception) {
                // Keep entry in-progress if connection fails; next refresh will re-check.
            } finally {
                if ($connection) {
                    @ftp_close($connection);
                }
            }
        }
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
