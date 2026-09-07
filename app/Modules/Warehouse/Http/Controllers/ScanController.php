<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Services\ScanResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** B11: one scan page for gun and phone camera; the code is resolved to a unit, a location or an ASN line. */
class ScanController extends Controller
{
    public function index(): View
    {
        return view('warehouse::scan.index');
    }

    public function resolve(Request $request, ScanResolver $resolver): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);
        $hit = $resolver->resolve($data['code']);

        if ($hit === null) {
            return redirect()->route('warehouse.scan.index')->withErrors(['code' => __('warehouse.scan.unknown', ['code' => $data['code']])]);
        }

        return redirect($hit['url'])->with('status', __('warehouse.scan.found_'.$hit['type'], ['label' => $hit['label']]));
    }
}
