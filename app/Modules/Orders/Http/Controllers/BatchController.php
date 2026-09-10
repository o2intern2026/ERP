<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\OrderBatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** A14 / OMS-12: every order generated from one container / ASN, with progress and (after M6) revenue. */
final class BatchController extends Controller
{
    public function index(Request $request, OrderBatchService $batches): View
    {
        $validated = $request->validate(['ref' => ['nullable', 'string', 'max:40']]);
        $reference = trim((string) ($validated['ref'] ?? '')); // validate() omits an absent key: coalesce before the cast, or the bare nav link 500s

        return view('orders::batches.index', ['reference' => $reference] + ($reference !== '' ? $batches->lookup($reference) : ['asns' => collect(), 'containers' => collect(), 'orders' => collect(), 'totals' => null]));
    }
}
