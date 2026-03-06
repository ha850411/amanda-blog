<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Social;
use Carbon\Carbon;

class SocialController extends Controller
{
    public function index()
    {
        $socials = Social::all();
        return response()->json([
            'data' => $socials,
        ]);
    }

    public function update(Request $request, $id)
    {
        $social = Social::find($id);
        if (!$social) {
            return response()->json([
                'message' => '社群 icon 不存在',
            ], 404);
        }

        // $social->picture = $request->input('picture', $social->picture);
        $social->url = $request->input('url', $social->url);
        $social->status = $request->input('status', $social->status);
        $social->save();

        return response()->json([
            'message' => '社群 icon 更新成功',
            'data' => $social,
        ]);
    }
}
