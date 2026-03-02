<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">FTP Transfers</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div id="live-message"></div>

            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800" id="server-status-message">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-700" id="server-error-message">
                    <ul class="list-disc list-inside text-sm space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">Upload to FTP</h3>
                    <p class="text-sm text-slate-500 mb-4">
                        If an upload is interrupted, upload the same file again in the same folder and it resumes automatically.
                    </p>

                    <form
                        id="ftp-upload-form"
                        action="{{ route('transfers.upload') }}"
                        data-chunk-url="{{ route('transfers.upload.chunk') }}"
                        data-complete-url="{{ route('transfers.upload.complete') }}"
                        method="POST"
                        enctype="multipart/form-data"
                        class="space-y-4"
                    >
                        @csrf

                        <div>
                            <x-input-label for="file" value="Select File" />
                            <input id="file" name="file" type="file" required class="mt-1 block w-full rounded-lg border-slate-300" />
                        </div>

                        <div>
                            <x-input-label for="remote_subdir" value="Remote Sub Directory (optional)" />
                            <x-text-input
                                id="remote_subdir"
                                name="remote_subdir"
                                type="text"
                                class="mt-1 block w-full"
                                placeholder="e.g. backups/2026"
                                value="{{ $currentDir }}"
                            />
                            <p class="mt-1 text-xs text-slate-500">Current folder: <span class="font-semibold">/{{ $currentDir ?: '' }}</span></p>
                        </div>

                        <div id="upload-progress-wrap" class="hidden rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-center justify-between text-xs text-slate-600 mb-2">
                                <span id="upload-progress-label">Preparing upload...</span>
                                <span id="upload-progress-percent">0%</span>
                            </div>
                            <div class="h-3 w-full rounded-full bg-slate-200 overflow-hidden">
                                <div id="upload-progress-bar" class="h-3 bg-indigo-600 transition-all duration-150" style="width: 0%"></div>
                            </div>
                            <p id="upload-progress-speed" class="mt-2 text-xs text-slate-500"></p>
                        </div>

                        <div class="flex gap-3">
                            <x-primary-button id="start-upload-btn">Start Upload</x-primary-button>
                            <button
                                type="button"
                                id="stop-upload-btn"
                                class="inline-flex items-center rounded-lg border border-rose-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-rose-700 hover:bg-rose-50 disabled:opacity-60 disabled:cursor-not-allowed"
                                disabled
                            >
                                Stop
                            </button>
                            <button
                                type="button"
                                id="resume-upload-btn"
                                class="inline-flex items-center rounded-lg border border-emerald-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-emerald-700 hover:bg-emerald-50 disabled:opacity-60 disabled:cursor-not-allowed"
                                disabled
                            >
                                Resume
                            </button>
                            <a href="{{ route('transfers.index', ['dir' => $currentDir]) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-700 hover:bg-slate-50">
                                Refresh
                            </a>
                        </div>
                    </form>

                    <div class="mt-4 flex items-center justify-between rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-sm gap-4 flex-wrap">
                        <div>
                            <p class="text-slate-500">Quota Used</p>
                            <p class="font-semibold text-slate-800">{{ number_format($quotaUsedGb, 2) }} GB</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Quota Total</p>
                            <p class="font-semibold text-slate-800">{{ $quotaTotalGb !== null ? number_format($quotaTotalGb, 2) . ' GB' : 'Unlimited' }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Home Directory</p>
                            <p class="font-semibold text-slate-800">{{ auth()->user()->home_directory }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Speed Limit</p>
                            <p class="font-semibold text-slate-800">{{ auth()->user()->speed_limit_kbps ? number_format(auth()->user()->speed_limit_kbps).' kbps' : 'Not Set' }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">FTP Connection</h3>
                    @php
                        $u = auth()->user();
                        $ftp = $ftpPreview ?? [
                            'host' => $u->ftp_host,
                            'port' => $u->ftp_port ?: 21,
                            'username' => $u->ftp_username,
                            'passive' => $u->ftp_passive,
                            'ssl' => $u->ftp_ssl,
                        ];
                    @endphp
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Local FTP</dt>
                            <dd class="font-medium text-slate-800">172.16.203.237:21</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Outside FTP</dt>
                            <dd class="font-medium text-slate-800">89.43.132.136:621</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">App FTP Target</dt>
                            <dd class="font-medium text-slate-800">{{ ($ftp['host'] ?: 'Not configured') . ':' . ($ftp['port'] ?: 21) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Username</dt>
                            <dd class="font-medium text-slate-800">{{ $ftp['username'] ?: 'Not configured' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">Passive</dt>
                            <dd class="font-medium text-slate-800">{{ $ftp['passive'] ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">SSL FTP</dt>
                            <dd class="font-medium text-slate-800">{{ $ftp['ssl'] ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                    </dl>
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('users.index') }}" class="mt-5 inline-block text-sm font-semibold text-indigo-600 hover:text-indigo-800">Manage user FTP settings</a>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h3 class="text-lg font-semibold text-slate-800">Remote Folder Browser</h3>
                    <div class="text-sm text-slate-600">
                        Current: <span class="font-semibold">/{{ $currentDir ?: '' }}</span>
                    </div>
                </div>

                @if ($browserError)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm mb-4">
                        {{ $browserError }}
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3 mb-4">
                    <a href="{{ route('transfers.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-slate-700 hover:bg-slate-50">
                        Root
                    </a>
                    @if ($parentDir !== null)
                        <a href="{{ route('transfers.index', ['dir' => $parentDir]) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-slate-700 hover:bg-slate-50">
                            Parent Folder
                        </a>
                    @endif

                    <form action="{{ route('transfers.folders.store') }}" method="POST" class="flex items-center gap-2 ml-auto">
                        @csrf
                        <input type="hidden" name="current_dir" value="{{ $currentDir }}">
                        <input
                            name="folder_name"
                            type="text"
                            required
                            placeholder="New folder name"
                            class="rounded-lg border-slate-300 text-sm"
                        />
                        <button type="submit" class="inline-flex items-center rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-slate-700">
                            Create Folder
                        </button>
                    </form>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 text-sm font-semibold text-slate-700">Folders</div>
                        <div class="max-h-64 overflow-auto">
                            @if (count($directories) === 0)
                                <p class="px-4 py-3 text-sm text-slate-500">No folders found.</p>
                            @else
                                <ul class="divide-y divide-slate-100">
                                    @foreach ($directories as $directory)
                                        <li>
                                            <a href="{{ route('transfers.index', ['dir' => $directory['relative_path']]) }}" class="block px-4 py-3 text-sm text-indigo-700 hover:bg-indigo-50">
                                                [DIR] {{ $directory['name'] }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 text-sm font-semibold text-slate-700">Files</div>
                        <div class="max-h-64 overflow-auto">
                            @if (count($files) === 0)
                                <p class="px-4 py-3 text-sm text-slate-500">No files found.</p>
                            @else
                                <ul class="divide-y divide-slate-100">
                                    @foreach ($files as $remoteFile)
                                        <li class="px-4 py-3 flex items-center justify-between gap-3 text-sm">
                                            <a
                                                href="{{ route('transfers.download', ['path' => $remoteFile['relative_path'], 'name' => $remoteFile['name']]) }}"
                                                class="text-indigo-700 hover:text-indigo-900 font-medium truncate"
                                                title="Download {{ $remoteFile['name'] }}"
                                            >
                                                {{ $remoteFile['name'] }}
                                            </a>
                                            <span class="text-slate-500 whitespace-nowrap">{{ number_format($remoteFile['size_bytes'] / 1024 / 1024, 2) }} MB</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 p-6">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-semibold text-slate-800">Transfer Activity</h3>
                    <p class="text-xs text-slate-500">Completed files are downloadable by clicking file names.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Started</th>
                                <th class="px-4 py-3 text-left">User</th>
                                <th class="px-4 py-3 text-left">File</th>
                                <th class="px-4 py-3 text-left">Path</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-right">Size</th>
                                <th class="px-4 py-3 text-right">Speed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($logs as $log)
                                <tr data-transfer-status="{{ $log->status }}">
                                    <td class="px-4 py-3 text-slate-600">{{ optional($log->started_at)->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    <td class="px-4 py-3 text-slate-800 font-medium">{{ $log->user?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-slate-700">
                                        @if ($log->status === 'completed')
                                            <a href="{{ route('transfers.download', ['log' => $log->id]) }}" class="font-medium text-indigo-700 hover:text-indigo-900 hover:underline">
                                                {{ $log->original_name }}
                                            </a>
                                        @else
                                            {{ $log->original_name }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">{{ $log->ftp_path }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold
                                            {{ $log->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                            {{ $log->status === 'failed' ? 'bg-rose-100 text-rose-700' : '' }}
                                            {{ $log->status === 'in_progress' ? 'bg-amber-100 text-amber-700' : '' }}">
                                            {{ str_replace('_', ' ', $log->status) }}
                                        </span>
                                        @if ($log->message)
                                            <p class="mt-1 text-xs text-slate-500">{{ $log->message }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-slate-800">{{ number_format($log->size_bytes / 1024 / 1024, 2) }} MB</td>
                                    <td class="px-4 py-3 text-right text-slate-600">
                                        @if ($log->speed_kbps !== null)
                                            {{ number_format($log->speed_kbps, 2) }} kbps
                                        @elseif ($log->status === 'in_progress')
                                            checking...
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-slate-500">No transfers recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-5">
                    {{ $logs->links() }}
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('ftp-upload-form');
            const fileInput = document.getElementById('file');
            const remoteSubdirInput = document.getElementById('remote_subdir');
            const progressWrap = document.getElementById('upload-progress-wrap');
            const progressBar = document.getElementById('upload-progress-bar');
            const progressPercent = document.getElementById('upload-progress-percent');
            const progressLabel = document.getElementById('upload-progress-label');
            const progressSpeed = document.getElementById('upload-progress-speed');
            const submitButton = document.getElementById('start-upload-btn');
            const stopButton = document.getElementById('stop-upload-btn');
            const resumeButton = document.getElementById('resume-upload-btn');
            const liveMessage = document.getElementById('live-message');

            if (!form) {
                return;
            }

            const chunkUrl = form.dataset.chunkUrl;
            const completeUrl = form.dataset.completeUrl;
            const token = form.querySelector('input[name="_token"]')?.value || '';
            const CHUNK_SIZE = 2 * 1024 * 1024;
            const defaultButtonText = submitButton.textContent;
            let uploadState = null;

            const hasPendingTransfers = document.querySelector('tr[data-transfer-status="in_progress"]') !== null;
            if (hasPendingTransfers) {
                setTimeout(function () {
                    window.location.reload();
                }, 15000);
            }

            const formatUploadSpeed = (kbitsPerSecond) => {
                if (!Number.isFinite(kbitsPerSecond) || kbitsPerSecond < 0) {
                    return '';
                }

                if (kbitsPerSecond >= 1024) {
                    return (kbitsPerSecond / 1024).toFixed(2) + ' Mbits/s';
                }

                return kbitsPerSecond.toFixed(2) + ' kbits/s';
            };

            const setUploadButtons = ({ uploading = false, paused = false, finalizing = false } = {}) => {
                submitButton.disabled = uploading || paused || finalizing;
                stopButton.disabled = !uploading;
                resumeButton.disabled = !paused;

                if (finalizing) {
                    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                    submitButton.textContent = 'Finalizing...';
                } else if (uploading) {
                    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                    submitButton.textContent = 'Uploading...';
                } else {
                    submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
                    submitButton.textContent = defaultButtonText;
                }
            };

            const safeToken = (value, maxLength = 24) => {
                if (!value) {
                    return '';
                }

                return String(value)
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '')
                    .slice(0, maxLength);
            };

            const buildUploadId = (file, targetDir) => {
                const dirPart = safeToken(targetDir || 'root', 20) || 'root';
                const filePart = safeToken(file.name || 'file', 28) || 'file';
                const sizePart = Number(file.size || 0);
                const modifiedPart = Number(file.lastModified || 0);
                const id = `up_${dirPart}_${filePart}_${sizePart}_${modifiedPart}`;

                return id.slice(0, 80);
            };

            const showMessage = (type, text) => {
                liveMessage.innerHTML = '';
                const box = document.createElement('div');
                box.className = type === 'success'
                    ? 'rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800'
                    : type === 'info'
                        ? 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800'
                        : 'rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-700';
                box.textContent = text;
                liveMessage.appendChild(box);
            };

            const resetProgressForNewUpload = () => {
                progressWrap.classList.remove('hidden');
                progressBar.style.width = '0%';
                progressPercent.textContent = '0%';
                progressLabel.textContent = 'Uploading...';
                progressSpeed.textContent = 'Current speed: 0.00 kbits/s';
            };

            const updateProgress = (loadedBytes) => {
                if (!uploadState || uploadState.fileSize <= 0) {
                    return;
                }

                const boundedLoaded = Math.max(0, Math.min(uploadState.fileSize, loadedBytes));
                const percent = Math.min(100, Math.round((boundedLoaded / uploadState.fileSize) * 100));
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';

                const seconds = Math.max(1, (Date.now() - uploadState.startedAt) / 1000);
                const kbitsPerSecond = (boundedLoaded * 8) / 1024 / seconds;
                progressSpeed.textContent = 'Current speed: ' + formatUploadSpeed(kbitsPerSecond);
            };

            const parsePayload = (xhr) => {
                try {
                    return JSON.parse(xhr.responseText || '{}');
                } catch (error) {
                    return {};
                }
            };

            const failUpload = (message) => {
                if (!uploadState) {
                    return;
                }

                uploadState.paused = true;
                progressLabel.textContent = 'Stopped';
                setUploadButtons({ uploading: false, paused: true });
                showMessage('error', message || 'Upload failed.');
            };

            const finalizeUpload = () => {
                if (!uploadState) {
                    return;
                }

                const xhr = new XMLHttpRequest();
                uploadState.activeXhr = xhr;
                uploadState.paused = false;
                progressLabel.textContent = 'Finalizing...';
                setUploadButtons({ uploading: false, paused: false, finalizing: true });

                const formData = new FormData();
                formData.append('_token', token);
                formData.append('upload_id', uploadState.uploadId);

                xhr.open('POST', completeUrl, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('Accept', 'application/json');

                xhr.onload = function () {
                    if (!uploadState || uploadState.activeXhr !== xhr) {
                        return;
                    }

                    uploadState.activeXhr = null;
                    setUploadButtons({ uploading: false, paused: false });

                    const payload = parsePayload(xhr);
                    if (xhr.status >= 200 && xhr.status < 300 && payload.ok) {
                        progressBar.style.width = '100%';
                        progressPercent.textContent = '100%';
                        progressLabel.textContent = 'Completed';
                        showMessage('success', payload.message || 'Upload completed.');
                        uploadState = null;

                        const redirectUrl = payload.redirect_url || window.location.href;
                        setTimeout(function () {
                            window.location.href = redirectUrl;
                        }, 700);
                        return;
                    }

                    failUpload(payload.message || 'Could not finalize upload.');
                };

                xhr.onabort = function () {
                    if (!uploadState || uploadState.activeXhr !== xhr) {
                        return;
                    }

                    uploadState.activeXhr = null;
                    uploadState.paused = true;
                    progressLabel.textContent = 'Stopped';
                    setUploadButtons({ uploading: false, paused: true });
                    showMessage('info', 'Upload stopped. Click Resume to continue.');
                };

                xhr.onerror = function () {
                    failUpload('Network error during upload finalization.');
                };

                xhr.send(formData);
            };

            const sendNextChunk = () => {
                if (!uploadState || uploadState.paused) {
                    return;
                }

                if (uploadState.uploadedBytes >= uploadState.fileSize) {
                    finalizeUpload();
                    return;
                }

                const chunkEnd = Math.min(uploadState.uploadedBytes + CHUNK_SIZE, uploadState.fileSize);
                const chunkBlob = uploadState.file.slice(uploadState.uploadedBytes, chunkEnd);
                const formData = new FormData();
                formData.append('_token', token);
                formData.append('upload_id', uploadState.uploadId);
                formData.append('file_name', uploadState.fileName);
                formData.append('file_size', String(uploadState.fileSize));
                formData.append('chunk_start', String(uploadState.uploadedBytes));
                formData.append('remote_subdir', uploadState.targetDir);
                formData.append('chunk', chunkBlob, uploadState.fileName);

                const xhr = new XMLHttpRequest();
                uploadState.activeXhr = xhr;
                setUploadButtons({ uploading: true, paused: false });

                xhr.open('POST', chunkUrl, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('Accept', 'application/json');

                xhr.upload.addEventListener('progress', function (e) {
                    if (!uploadState || !e.lengthComputable) {
                        return;
                    }

                    progressLabel.textContent = 'Uploading...';
                    updateProgress(uploadState.uploadedBytes + e.loaded);
                });

                xhr.onload = function () {
                    if (!uploadState || uploadState.activeXhr !== xhr) {
                        return;
                    }

                    uploadState.activeXhr = null;
                    const payload = parsePayload(xhr);

                    if (xhr.status >= 200 && xhr.status < 300 && payload.ok) {
                        const serverUploaded = Number(payload.uploaded_bytes);
                        if (
                            uploadState.uploadedBytes === 0 &&
                            Number.isFinite(serverUploaded) &&
                            serverUploaded > 0 &&
                            serverUploaded < uploadState.fileSize
                        ) {
                            progressLabel.textContent = 'Resuming...';
                        }
                        uploadState.uploadedBytes = Number.isFinite(serverUploaded) ? serverUploaded : chunkEnd;
                        updateProgress(uploadState.uploadedBytes);

                        if (payload.done || uploadState.uploadedBytes >= uploadState.fileSize) {
                            finalizeUpload();
                            return;
                        }

                        sendNextChunk();
                        return;
                    }

                    failUpload(payload.message || 'Chunk upload failed.');
                };

                xhr.onerror = function () {
                    failUpload('Network error during upload.');
                };

                xhr.onabort = function () {
                    if (!uploadState || uploadState.activeXhr !== xhr) {
                        return;
                    }

                    uploadState.activeXhr = null;
                    uploadState.paused = true;
                    progressLabel.textContent = 'Stopped';
                    setUploadButtons({ uploading: false, paused: true });
                    showMessage('info', 'Upload stopped. Click Resume to continue.');
                };

                xhr.send(formData);
            };

            const startNewUpload = () => {
                if (!window.XMLHttpRequest) {
                    showMessage('error', 'This browser does not support upload progress.');
                    return;
                }

                const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                if (!file) {
                    showMessage('error', 'Please choose a file first.');
                    return;
                }

                const targetDir = (remoteSubdirInput?.value || '').trim();
                const uploadId = buildUploadId(file, targetDir);

                uploadState = {
                    uploadId: uploadId,
                    file: file,
                    fileName: file.name,
                    fileSize: file.size,
                    uploadedBytes: 0,
                    startedAt: Date.now(),
                    targetDir: targetDir,
                    paused: false,
                    activeXhr: null,
                };

                resetProgressForNewUpload();
                setUploadButtons({ uploading: true, paused: false });
                sendNextChunk();
            };

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (uploadState && uploadState.activeXhr) {
                    return;
                }

                startNewUpload();
            });

            stopButton.addEventListener('click', function () {
                if (uploadState && uploadState.activeXhr) {
                    uploadState.activeXhr.abort();
                }
            });

            resumeButton.addEventListener('click', function () {
                if (!uploadState || uploadState.activeXhr || !uploadState.paused) {
                    return;
                }

                uploadState.paused = false;
                progressLabel.textContent = uploadState.uploadedBytes >= uploadState.fileSize ? 'Finalizing...' : 'Resuming...';
                setUploadButtons({ uploading: true, paused: false });

                if (uploadState.uploadedBytes >= uploadState.fileSize) {
                    finalizeUpload();
                } else {
                    sendNextChunk();
                }
            });

            fileInput.addEventListener('change', function () {
                if (uploadState && !uploadState.activeXhr) {
                    uploadState = null;
                    setUploadButtons({ uploading: false, paused: false });
                }
            });

            setUploadButtons({ uploading: false, paused: false });
        });
    </script>
</x-app-layout>
