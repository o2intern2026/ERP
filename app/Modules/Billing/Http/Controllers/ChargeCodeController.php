<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\ChargeCode;
use Illuminate\Contracts\View\View;

/** A24: the catalogue with its trigger rules — read-only here; codes are contract (charge-codes.md). */
class ChargeCodeController extends Controller
{
    public function index(): View
    {
        return view('billing::charge_codes.index', ['codes' => ChargeCode::query()->with('rules')->orderBy('category')->orderBy('code')->get()]);
    }
}
