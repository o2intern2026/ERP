<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** A23: register endpoints (URL + events), see recent deliveries. Secrets are generated here and shown to admins only. */
class WebhookController extends Controller
{
    public function index(): View
    {
        return view('platform::webhooks.index', [
            'endpoints' => WebhookEndpoint::query()->withCount('deliveries')->orderBy('name')->get(),
            'deliveries' => WebhookDelivery::query()->with('endpoint')->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url', 'max:500', 'starts_with:https://,http://localhost,http://127.0.0.1'],
            'events' => ['required', 'string', 'max:2000'],
        ]);
        $events = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $data['events']) ?: []))));

        WebhookEndpoint::query()->create(['name' => $data['name'], 'url' => $data['url'], 'secret' => Str::random(48), 'events' => $events ?: ['*'], 'active' => true, 'created_by' => auth()->id()]);

        return back()->with('status', __('platform.webhooks.created'));
    }

    public function toggle(WebhookEndpoint $endpoint): RedirectResponse
    {
        $endpoint->update(['active' => ! $endpoint->active]);

        return back()->with('status', __('platform.webhooks.saved'));
    }

    public function destroy(WebhookEndpoint $endpoint): RedirectResponse
    {
        $endpoint->delete();

        return back()->with('status', __('platform.webhooks.deleted'));
    }
}
