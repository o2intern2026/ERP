<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Search\SearchRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** A30: one box — Job, ASN, container, consignment mark, unit label, client; modules add their own hits via SearchRegistry. */
class SearchController extends Controller
{
    public function index(Request $request, SearchRegistry $registry): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('platform::search.index', ['q' => $q, 'results' => $registry->search($q)]);
    }
}
