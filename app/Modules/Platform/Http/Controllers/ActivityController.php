<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/** A20 audit log (PLT-8): who changed what, when — from spatie/laravel-activitylog on the models that matter. */
class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['subject_type' => ['nullable', 'string', 'max:120'], 'causer_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $subjectTypes = Activity::query()->select('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')->filter()->values();

        return view('platform::activity.index', [
            'activities' => Activity::query()->with(['causer', 'subject'])
                ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
                ->when($filters['causer_id'] ?? null, fn ($q, $v) => $q->where('causer_id', $v))
                ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
                ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)->addDay()))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'subjectTypes' => $subjectTypes,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
