<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class IndexController extends Controller
{
    public function __invoke(): View
    {
        return view('reports::index');
    }
}
