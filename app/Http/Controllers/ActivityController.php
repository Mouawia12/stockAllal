<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Support\ActivityText;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Journal (developpeur) : qui a change quoi et quand dans les comptes et les branches, le plus recent d'abord. */
class ActivityController extends Controller
{
    /** Filtres de la page (?type=) : le type de ce qui a change. */
    public const TYPES = [
        'all' => null,
        'users' => ['user'],
        'branches' => ['branch', 'install_code'],
    ];

    public function index(Request $request): View
    {
        $type = array_key_exists((string) $request->query('type'), self::TYPES) ? (string) $request->query('type') : 'all';
        $logs = ActivityLog::with('user')
            ->when(self::TYPES[$type] !== null, fn ($q) => $q->whereIn('subject_type', self::TYPES[$type]))
            ->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString();
        $text = new ActivityText(Branch::pluck('name', 'id'));

        return view('activity.index', [
            'type' => $type,
            'logs' => $logs,
            'entries' => $logs->getCollection()->map(fn (ActivityLog $log) => ['log' => $log] + $text->describe($log)),
        ]);
    }
}
