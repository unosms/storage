<?php

namespace App\Http\Controllers;

use App\Models\TransferLog;
use App\Services\SystemMonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringController extends Controller
{
    public function __construct(private readonly SystemMonitorService $monitor)
    {
    }

    public function live(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->can_view_monitoring) {
            abort(403, 'Monitoring permission is required.');
        }

        $activeSessions = DB::table('sessions')
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
            ->count();

        return response()->json([
            'monitor' => $this->monitor->snapshot(),
            'in_progress' => TransferLog::where('status', 'in_progress')->count(),
            'active_sessions' => $activeSessions,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
