<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use Illuminate\Contracts\View\View;

class IndexController extends Controller
{
    public function __invoke(): View
    {
        return view('transport::index', [
            'shipments' => Shipment::query()
                ->with(['job', 'carrier', 'selectedQuote'])
                ->withCount('quotes')
                ->latest('id')
                ->paginate(25),
        ]);
    }
}
