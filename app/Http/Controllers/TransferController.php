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
        $ftpPreview = $this->resolveFtpConfig($user, true);
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
        $breadcrumbs = $this->buildBreadcrumbs('');
        $browserStats = [
            'dir_count' => 0,
            'file_count' => 0,
            'total_size_bytes' => 0,
            'total_size_label' => '0 B',
        ];
        $browserError = null;

        try {
            $currentDir = $this->normalizeRelativePath((string) $request->query('dir', ''));
            [$directories, $files, $parentDir, $browserStats] = $this->listRemoteEntries($user, $currentDir);
            $breadcrumbs = $this->buildBreadcrumbs($currentDir);
        } catch (Throwable $exception) {
            $browserError = $exception->getMessage();
        }

        return view('transfers.index', [
            'logs' => $logs,
            'quotaUsedGb' => round($user->used_space_bytes / 1024 / 1024 / 1024, 2),
            'quotaTotalGb' => $user->quota_mb > 0 ? round($user->quota_mb / 1024, 2) : null,
            'ftpPreview' => $ftpPreview,
            'currentDir' => $currentDir,
            'parentDir' => $parentDir,
            'breadcrumbs' => $breadcrumbs,
            'browserStats' => $browserStats,
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

    public function uploadChunk(Request $request)
    {
        $user = $request->user();

        if (! $user->can_upload && ! $user->isAdmin()) {
            return response()->json([
                'ok' => false,
                'message' => 'You do not have upload permission.',
            ], 403);
        }

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $data = $request->validate([
            'upload_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1'],
            'chunk_start' => ['required', 'integer', 'min:0'],
            'remote_subdir' => ['nullable', 'string', 'max:255'],
            'chunk' => ['required', 'file'],
        ]);

        $fileSize = (int) $data['file_size'];
        if ($user->quota_mb > 0 && ($user->used_space_bytes + $fileSize) > $user->quotaBytes()) {
            return response()->json([
                'ok' => false,
                'message' => 'Quota exceeded for this user.',
            ], 422);
        }

        try {
            $targetDir = $this->normalizeRelativePath((string) ($data['remote_subdir'] ?? ''));
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $uploadId = (string) $data['upload_id'];
        $safeName = $this->sanitizeFilename((string) $data['file_name']);
        [$partPath, $metaPath] = $this->chunkPaths($user, $uploadId);

        $uploadedBytes = is_file($partPath) ? (int) (filesize($partPath) ?: 0) : 0;
        $chunkStart = (int) $data['chunk_start'];

        if ($chunkStart > $uploadedBytes) {
            return response()->json([
                'ok' => false,
                'message' => 'Upload offset mismatch. Please resume upload.',
                'uploaded_bytes' => $uploadedBytes,
            ], 409);
        }

        if ($chunkStart < $uploadedBytes) {
            return response()->json([
                'ok' => true,
                'upload_id' => $uploadId,
                'uploaded_bytes' => $uploadedBytes,
                'file_size' => $fileSize,
                'done' => $uploadedBytes >= $fileSize,
            ]);
        }

        $chunkFile = $request->file('chunk');
        $chunkPath = $chunkFile?->getRealPath();
        if (! $chunkPath || ! is_file($chunkPath)) {
            return response()->json([
                'ok' => false,
                'message' => 'Uploaded chunk is not readable.',
            ], 422);
        }

        $chunkBytes = (int) (filesize($chunkPath) ?: 0);
        if ($chunkBytes <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Chunk is empty.',
            ], 422);
        }

        $remaining = max(0, $fileSize - $uploadedBytes);
        $writeLimit = min($chunkBytes, $remaining);
        if ($writeLimit <= 0) {
            return response()->json([
                'ok' => true,
                'upload_id' => $uploadId,
                'uploaded_bytes' => $uploadedBytes,
                'file_size' => $fileSize,
                'done' => true,
            ]);
        }

        $in = @fopen($chunkPath, 'rb');
        $out = @fopen($partPath, 'ab');
        if (! is_resource($in) || ! is_resource($out)) {
            if (is_resource($in)) {
                @fclose($in);
            }
            if (is_resource($out)) {
                @fclose($out);
            }

            return response()->json([
                'ok' => false,
                'message' => 'Could not open temporary upload storage.',
            ], 500);
        }

        $copied = @stream_copy_to_stream($in, $out, $writeLimit);
        @fclose($in);
        @fclose($out);

        if (! is_int($copied) || $copied < 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to write upload chunk.',
            ], 500);
        }

        $uploadedBytes = min($fileSize, $uploadedBytes + $copied);

        $meta = [
            'user_id' => $user->id,
            'upload_id' => $uploadId,
            'file_name' => (string) $data['file_name'],
            'safe_name' => $safeName,
            'file_size' => $fileSize,
            'target_dir' => $targetDir,
            'updated_at' => now()->toISOString(),
        ];

        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'ok' => true,
            'upload_id' => $uploadId,
            'uploaded_bytes' => $uploadedBytes,
            'file_size' => $fileSize,
            'done' => $uploadedBytes >= $fileSize,
        ]);
    }

    public function uploadComplete(Request $request)
    {
        $user = $request->user();

        if (! $user->can_upload && ! $user->isAdmin()) {
            return response()->json([
                'ok' => false,
                'message' => 'You do not have upload permission.',
            ], 403);
        }

        $data = $request->validate([
            'upload_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $uploadId = (string) $data['upload_id'];
        [$partPath, $metaPath] = $this->chunkPaths($user, $uploadId);

        if (! is_file($partPath) || ! is_file($metaPath)) {
            return response()->json([
                'ok' => false,
                'message' => 'Upload session not found. Please start upload again.',
            ], 404);
        }

        $metaRaw = @file_get_contents($metaPath);
        $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
        if (! is_array($meta) || ((int) ($meta['user_id'] ?? 0)) !== $user->id) {
            return response()->json([
                'ok' => false,
                'message' => 'Upload metadata is invalid.',
            ], 422);
        }

        $fileSize = (int) ($meta['file_size'] ?? 0);
        $uploadedBytes = (int) (filesize($partPath) ?: 0);
        if ($fileSize <= 0 || $uploadedBytes < $fileSize) {
            return response()->json([
                'ok' => false,
                'message' => 'Upload is not complete yet. Please resume upload.',
                'uploaded_bytes' => $uploadedBytes,
                'file_size' => $fileSize,
            ], 409);
        }

        if ($user->quota_mb > 0 && ($user->used_space_bytes + $fileSize) > $user->quotaBytes()) {
            return response()->json([
                'ok' => false,
                'message' => 'Quota exceeded for this user.',
            ], 422);
        }

        $safeName = $this->sanitizeFilename((string) ($meta['safe_name'] ?? $meta['file_name'] ?? 'file.bin'));
        $targetDir = $this->normalizeRelativePath((string) ($meta['target_dir'] ?? ''));
        $relativePath = $this->joinRelativePath($targetDir, $safeName);
        $absoluteRemotePath = $this->absoluteFtpPath($user, $relativePath);
        $absoluteTargetDir = $this->absoluteFtpPath($user, $targetDir);

        $log = TransferLog::create([
            'user_id' => $user->id,
            'direction' => 'upload',
            'status' => 'in_progress',
            'original_name' => (string) ($meta['file_name'] ?? $safeName),
            'filename' => $safeName,
            'ftp_path' => $relativePath,
            'size_bytes' => $fileSize,
            'started_at' => now(),
            'client_ip' => $request->ip(),
        ]);

        try {
            $result = $this->uploadFileWithResume(
                $user,
                $partPath,
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

            @unlink($partPath);
            @unlink($metaPath);

            return response()->json([
                'ok' => true,
                'message' => 'Upload completed to FTP: ' . $relativePath,
                'path' => $relativePath,
                'speed_kbps' => $speedKbps,
                'redirect_url' => route('transfers.index', ['dir' => $targetDir]),
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => Str::limit($exception->getMessage(), 500),
            ]);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
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

    public function deleteEntry(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'type' => ['required', 'in:file,folder'],
            'current_dir' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $path = $this->normalizeRelativePath((string) $request->input('path'));
            $type = (string) $request->input('type');
            $currentDir = $this->normalizeRelativePath((string) $request->input('current_dir', ''));
        } catch (Throwable $exception) {
            return redirect()
                ->route('transfers.index')
                ->withErrors(['upload' => 'Delete failed: ' . $exception->getMessage()]);
        }

        if ($path === '') {
            return redirect()
                ->route('transfers.index', ['dir' => $currentDir])
                ->withErrors(['upload' => 'Delete failed: Invalid path.']);
        }

        $connection = null;

        try {
            $connection = $this->openFtpConnection($user);
            $absolutePath = $this->absoluteFtpPath($user, $path);

            if ($type === 'folder') {
                $this->deleteFtpDirectoryRecursive($connection, $absolutePath);
            } else {
                if (! @ftp_delete($connection, $absolutePath)) {
                    throw new Exception('Could not delete file on FTP.');
                }
            }

            return redirect()
                ->route('transfers.index', ['dir' => $currentDir])
                ->with('status', ucfirst($type) . " deleted: {$path}");
        } catch (Throwable $exception) {
            return redirect()
                ->route('transfers.index', ['dir' => $currentDir])
                ->withErrors(['upload' => 'Delete failed: ' . $exception->getMessage()]);
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
        $maxAttempts = 2;
        $attempt = 0;
        $lastError = 'Could not read remote folder list.';

        while ($attempt < $maxAttempts) {
            $attempt++;
            $connection = null;

            try {
                $connection = $this->openFtpConnection($user);
                $absoluteDir = $this->absoluteFtpPath($user, $currentDir);

                $entries = $this->readEntriesFromMlsd($connection, $absoluteDir);
                if ($entries === null) {
                    $entries = $this->readEntriesFromRawList($connection, $absoluteDir);
                }
                if ($entries === null) {
                    $entries = $this->readEntriesFromNlist($connection, $absoluteDir);
                }

                if ($entries === null) {
                    throw new Exception('Could not read remote folder list.');
                }

                $directories = [];
                $files = [];
                $seen = [];

                foreach ($entries as $entry) {
                    $name = $this->normalizeEntryName((string) ($entry['name'] ?? ''));
                    if ($name === '' || isset($seen[$name])) {
                        continue;
                    }
                    $seen[$name] = true;

                    $entryRelativePath = $this->joinRelativePath($currentDir, $name);

                    if ((bool) ($entry['is_dir'] ?? false)) {
                        $directories[] = [
                            'name' => $name,
                            'relative_path' => $entryRelativePath,
                        ];
                    } else {
                        $size = max(0, (int) ($entry['size'] ?? 0));
                        $files[] = [
                            'name' => $name,
                            'relative_path' => $entryRelativePath,
                            'size_bytes' => $size,
                            'size_label' => $this->humanFileSize($size),
                        ];
                    }
                }

                usort($directories, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
                usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

                $parentDir = null;
                if ($currentDir !== '') {
                    $parentDir = str_contains($currentDir, '/')
                        ? Str::beforeLast($currentDir, '/')
                        : '';
                }

                $browserStats = $this->buildBrowserStats($directories, $files);

                return [$directories, $files, $parentDir, $browserStats];
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                if ($attempt >= $maxAttempts) {
                    break;
                }
                usleep(150000);
            } finally {
                if ($connection) {
                    @ftp_close($connection);
                }
            }
        }

        throw new Exception($lastError);
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

    private function readEntriesFromMlsd($connection, string $absoluteDir): ?array
    {
        if (! function_exists('ftp_mlsd')) {
            return null;
        }

        $list = @ftp_mlsd($connection, $absoluteDir);
        if (! is_array($list)) {
            return null;
        }

        $entries = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $type = strtolower((string) ($row['type'] ?? ''));
            $entries[] = [
                'name' => $name,
                'is_dir' => in_array($type, ['dir', 'cdir', 'pdir'], true),
                'size' => (int) ($row['size'] ?? 0),
            ];
        }

        return $entries;
    }

    private function readEntriesFromRawList($connection, string $absoluteDir): ?array
    {
        $rawList = @ftp_rawlist($connection, $absoluteDir);
        if (! is_array($rawList)) {
            return null;
        }

        $entries = [];
        foreach ($rawList as $line) {
            $entry = $this->parseRawListLine((string) $line);
            if ($entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function readEntriesFromNlist($connection, string $absoluteDir): ?array
    {
        $nlist = @ftp_nlist($connection, $absoluteDir);
        if (! is_array($nlist)) {
            return null;
        }

        $entries = [];
        foreach ($nlist as $item) {
            $item = str_replace('\\', '/', trim((string) $item));
            if ($item === '' || str_ends_with($item, '/.') || str_ends_with($item, '/..')) {
                continue;
            }

            $name = basename($item);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $target = '/' . ltrim($this->normalizeRelativePath(trim($absoluteDir, '/') . '/' . $name), '/');
            $size = (int) @ftp_size($connection, $target);

            $entries[] = [
                'name' => $name,
                'is_dir' => $size < 0,
                'size' => $size > 0 ? $size : 0,
            ];
        }

        return $entries;
    }

    private function normalizeEntryName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = basename($name);

        if ($name === '.' || $name === '..') {
            return '';
        }

        return $name;
    }

    private function buildBrowserStats(array $directories, array $files): array
    {
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += (int) ($file['size_bytes'] ?? 0);
        }

        return [
            'dir_count' => count($directories),
            'file_count' => count($files),
            'total_size_bytes' => $totalSize,
            'total_size_label' => $this->humanFileSize($totalSize),
        ];
    }

    private function buildBreadcrumbs(string $currentDir): array
    {
        $breadcrumbs = [
            ['label' => 'Root', 'dir' => ''],
        ];

        if ($currentDir === '') {
            return $breadcrumbs;
        }

        $parts = array_filter(explode('/', $currentDir), fn ($part) => $part !== '');
        $acc = '';

        foreach ($parts as $part) {
            $acc = $this->joinRelativePath($acc, $part);
            $breadcrumbs[] = [
                'label' => $part,
                'dir' => $acc,
            ];
        }

        return $breadcrumbs;
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, 2) . ' ' . $units[$unitIndex];
    }

    private function deleteFtpDirectoryRecursive($connection, string $absoluteDir): void
    {
        $rawList = @ftp_rawlist($connection, $absoluteDir);
        if (is_array($rawList)) {
            foreach ($rawList as $line) {
                $entry = $this->parseRawListLine((string) $line);
                if (! $entry) {
                    continue;
                }

                $childPath = rtrim($absoluteDir, '/') . '/' . $entry['name'];
                if ($entry['is_dir']) {
                    $this->deleteFtpDirectoryRecursive($connection, $childPath);
                } else {
                    @ftp_delete($connection, $childPath);
                }
            }
        }

        if (! @ftp_rmdir($connection, $absoluteDir)) {
            throw new Exception('Could not delete folder on FTP.');
        }
    }

    private function openFtpConnection(User $user)
    {
        $ftp = $this->resolveFtpConfig($user, true);
        $this->assertFtpConfigured($ftp);

        $connection = $ftp['ssl']
            ? @ftp_ssl_connect($ftp['host'], $ftp['port'], 30)
            : @ftp_connect($ftp['host'], $ftp['port'], 30);

        if (! $connection) {
            throw new Exception('Could not connect to FTP host.');
        }

        if (! @ftp_login($connection, $ftp['username'], $ftp['password'])) {
            @ftp_close($connection);
            throw new Exception($this->ftpLoginFailedMessage($user, $ftp['host'], $ftp['port'], $ftp['username']));
        }

        @ftp_pasv($connection, $ftp['passive']);

        return $connection;
    }

    private function assertFtpConfigured(array $ftp): void
    {
        if (! function_exists('ftp_connect')) {
            throw new Exception('PHP FTP extension is not enabled on this server.');
        }

        if (! $ftp['host'] || ! $ftp['username']) {
            throw new Exception('FTP settings are not complete. Ask admin to set host/username.');
        }

        if (! $ftp['password']) {
            throw new Exception('FTP password is missing for this user. Open User Manager, edit the user, set a password, and save.');
        }
    }

    private function resolveFtpConfig(User $user, bool $persistFallbacks = false): array
    {
        $resolvedHost = trim((string) ($user->ftp_host ?? ''));
        if ($resolvedHost === '') {
            $resolvedHost = (string) config('storage_manager.ftp.host', '127.0.0.1');
        }

        $resolvedPort = (int) ($user->ftp_port ?: (int) config('storage_manager.ftp.port', 21));
        if ($resolvedPort <= 0) {
            $resolvedPort = (int) config('storage_manager.ftp.port', 21);
        }

        $resolvedUsername = trim((string) ($user->ftp_username ?? ''));
        if ($resolvedUsername === '') {
            $resolvedUsername = $this->fallbackFtpUsername($user);
        }

        $resolvedPassword = trim((string) ($user->ftp_password ?? ''));
        if ($resolvedPassword === '') {
            $resolvedPassword = trim((string) config('storage_manager.ftp.default_password', ''));
        }

        $resolvedPassive = (bool) ($user->ftp_passive ?? (bool) config('storage_manager.ftp.passive', true));
        $resolvedSsl = (bool) ($user->ftp_ssl ?? (bool) config('storage_manager.ftp.ssl', false));

        if ($persistFallbacks) {
            $updates = [];

            if (! $user->ftp_host && $resolvedHost !== '') {
                $updates['ftp_host'] = $resolvedHost;
            }

            if ((! $user->ftp_port || (int) $user->ftp_port <= 0) && $resolvedPort > 0) {
                $updates['ftp_port'] = $resolvedPort;
            }

            if (! $user->ftp_username && $resolvedUsername !== '') {
                $updates['ftp_username'] = $resolvedUsername;
            }

            if (! $user->ftp_password && $resolvedPassword !== '') {
                $updates['ftp_password'] = $resolvedPassword;
            }

            if (! empty($updates)) {
                $user->forceFill($updates)->save();
                $user->refresh();
            }
        }

        return [
            'host' => $resolvedHost,
            'port' => $resolvedPort,
            'username' => $resolvedUsername,
            'password' => $resolvedPassword,
            'passive' => $resolvedPassive,
            'ssl' => $resolvedSsl,
        ];
    }

    private function fallbackFtpUsername(User $user): string
    {
        $emailPrefix = '';
        if (str_contains((string) $user->email, '@')) {
            $emailPrefix = (string) Str::before((string) $user->email, '@');
        }

        $seed = $emailPrefix !== '' ? $emailPrefix : (string) $user->name;
        $candidate = Str::of($seed)
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]+/', '')
            ->trim('._-')
            ->limit(32, '')
            ->value();

        return $candidate !== '' ? $candidate : ('user' . $user->id);
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

    private function chunkDirectory(User $user): string
    {
        $dir = storage_path('app/upload-chunks/' . $user->id);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function chunkPaths(User $user, string $uploadId): array
    {
        $safeUploadId = preg_replace('/[^A-Za-z0-9_-]/', '', $uploadId) ?: 'upload';
        $base = $this->chunkDirectory($user) . DIRECTORY_SEPARATOR . $safeUploadId;

        return [
            $base . '.part',
            $base . '.json',
        ];
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

    private function ftpLoginFailedMessage(User $user, string $host, int $port, string $username): string
    {
        $message = "FTP login failed for '{$username}' at {$host}:{$port}.";

        if (PHP_OS_FAMILY !== 'Linux') {
            return $message;
        }

        if (function_exists('posix_getpwnam')) {
            $account = @posix_getpwnam($username);
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
