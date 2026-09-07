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
        $reference = trim((string) $request->validate(['ref' => ['nullable', 'string', 'max:40']])['ref'] ?? '');

        return view('orders::batches.index', ['reference' => $reference] + ($reference !== '' ? $batches->lookup($reference) : ['asns' => collect(), 'containers' => collect(), 'orders' => collect(), 'totals' => null]));
    }
}
