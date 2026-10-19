<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProfitReport;
use Illuminate\Http\Request;

class ProfitController extends Controller
{
    public function index(Request $request)
    {
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90, 365], true) ? (int) $request->query('days') : 30;
        $r = new ProfitReport($days);

        return view('admin.profit', ['days' => $days, 's' => $r->summary(), 'products' => $r->products()]);
    }
}
