<?php

namespace App\Http\Controllers;

use App\Models\TransferLog;
use App\Models\User;
use App\Services\SystemMonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private readonly SystemMonitorService $monitor)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $activeSessions = DB::table('sessions')
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
            ->count();

        $inProgressTransfers = TransferLog::where('status', 'in_progress')->count();

        $totalUploads = TransferLog::where('status', 'completed')
            ->where('direction', 'upload')
            ->count();

        $transferredBytes = TransferLog::where('status', 'completed')
            ->where('direction', 'upload')
            ->sum('size_bytes');

        $recentTransfersQuery = TransferLog::with('user')
            ->latest('started_at')
            ->latest('id');

        if (! $user->isAdmin()) {
            $recentTransfersQuery->where('user_id', $user->id);
        }

        $recentTransfers = $recentTransfersQuery->limit(8)->get();

        $stats = [
            'users_total' => User::count(),
            'admins_total' => User::where('role', 'admin')->count(),
            'active_sessions' => $activeSessions,
            'in_progress' => $inProgressTransfers,
            'total_uploads' => $totalUploads,
            'transferred_gb' => round($transferredBytes / 1024 / 1024 / 1024, 2),
            'total_quota_gb' => round(User::sum('quota_mb') / 1024, 2),
            'used_quota_gb' => round(User::sum('used_space_bytes') / 1024 / 1024 / 1024, 2),
        ];

        return view('dashboard', [
            'stats' => $stats,
            'monitor' => $this->monitor->snapshot(),
            'recentTransfers' => $recentTransfers,
            'canViewMonitoring' => $user->isAdmin() || $user->can_view_monitoring,
        ]);
    }
}
