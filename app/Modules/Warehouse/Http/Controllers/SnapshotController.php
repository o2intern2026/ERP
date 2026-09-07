<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** B10a: look at any day's snapshot by client — pallets by class / source, carton units, pickface slots. */
class SnapshotController extends Controller
{
    public function index(Request $request, SnapshotService $snapshots): View
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $date = isset($data['date']) ? Carbon::parse($data['date']) : today();

        $clients = Client::query()->pluck('name', 'id');
        $warehouses = Warehouse::query()->pluck('code', 'id');

        return view('warehouse::snapshots.index', [
            'date' => $date,
            'rows' => $snapshots->summary($date)->map(fn ($r) => $r + ['client' => $clients[$r['client_id']] ?? '#'.$r['client_id'], 'warehouse' => $warehouses[$r['warehouse_id']] ?? '#'.$r['warehouse_id']]),
        ]);
    }
}
