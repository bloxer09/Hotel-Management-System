<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $query = AuditLog::with('user');

        // Apply filters
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('keyword')) {
            $kw = '%' . $request->keyword . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('action', 'like', $kw)
                  ->orWhere('reason', 'like', $kw)
                  ->orWhere('old_value', 'like', $kw)
                  ->orWhere('new_value', 'like', $kw);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->date_from));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->date_to));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'user_id', 'module', 'action', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $logs = $query->orderBy($sortBy, $sortDir)->paginate(15)->withQueryString();

        $users = User::orderBy('username', 'asc')->get(['id', 'username', 'full_name', 'role']);
        $modules = AuditLog::select('module')->distinct()->whereNotNull('module')->pluck('module');

        return Inertia::render('Settings/Audit', [
            'logs' => $logs,
            'users' => $users,
            'modules' => $modules,
            'filters' => $request->only(['user_id', 'module', 'keyword', 'date_from', 'date_to']),
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }
}

