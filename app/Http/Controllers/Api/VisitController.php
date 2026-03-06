<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Visit;

class VisitController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => [
                "today" => Visit::where("date", date('Y/m/d'))->count(),
                "total" => Visit::count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $visit = Visit::create([
            'ip' => $request->ip(),
            'date' => date('Y/m/d'),
        ]);

        return response()->json([
            'message' => '訪問紀錄新增成功',
            'data' => $visit,
        ]);
    }
}
