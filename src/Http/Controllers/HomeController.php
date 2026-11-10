<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('orrery::layout', [
            'isDownForMaintenance' => App::isDownForMaintenance(),
        ]);
    }
}
