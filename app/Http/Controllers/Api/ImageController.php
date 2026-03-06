<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $path = $request->file('upload')->store('images', 's3');
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '上傳失敗：' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => '上傳成功',
            'url' => Storage::disk('s3')->url($path),
        ]);
    }
}
