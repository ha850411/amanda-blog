<?php

namespace App\Http\Controllers\Api;

use App\Models\About;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class AboutController extends Controller
{
    public function updateAbout(Request $request)
    {
        $about = About::first();

        $update = [];

        // 處理頭像上傳到 S3
        if ($request->hasFile('avatar')) {
            // 刪除 S3 上的舊圖片
            if ($about?->picture) {
                Storage::disk('s3')->delete($about->picture);
            }
            try {
                // 上傳新圖片，回傳相對路徑
                $update['picture'] = $request->file('avatar')->store('avatars', 's3');
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => '圖片上傳失敗',
                ], 500);
            }
        }

        $update = [
            ...$update,
            ...$request->only([
                'title',
                'sub_title',
                'description',
            ]),
        ];

        if ($about) {
            $about->update($update);
        } else {
            $about = About::create($update);
        }

        return response()->json([
            'success' => true,
            'message' => '更新成功',
            'update' => $update,
        ]);
    }
}
