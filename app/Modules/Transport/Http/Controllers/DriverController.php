<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DriverController extends Controller
{
    public function __invoke(): View
    {
        return view('transport::driver');
    }
}
