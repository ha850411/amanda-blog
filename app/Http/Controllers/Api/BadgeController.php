<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use Exception;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    /**
     * 取得所有徽章列表
     */
    public function index(Request $request)
    {
        $query = Badge::query()->orderBy('sort', 'asc')->orderBy('id', 'asc');

        if ($request->has('status') && $request->input('status') !== '' && $request->input('status') !== null) {
            $query->where('status', (int) $request->input('status'));
        }

        $badges = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $badges,
        ]);
    }

    /**
     * 新增徽章
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|string|max:50',
            'status' => 'nullable|in:0,1',
            'sort' => 'nullable|integer',
        ]);

        try {
            $maxSort = Badge::max('sort') ?? 0;
            $color = trim((string) $request->input('color'));
            $status = $request->has('status') ? (int) $request->input('status') : 1;
            $sort = $request->filled('sort') ? (int) $request->input('sort') : ($maxSort + 1);

            $badge = Badge::create([
                'name' => trim((string) $request->input('name')),
                'color' => $color,
                'status' => $status,
                'sort' => $sort,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => '徽章新增成功',
                'data' => $badge,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 修改徽章
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'color' => 'sometimes|required|string|max:50',
            'status' => 'nullable|in:0,1',
            'sort' => 'nullable|integer',
        ]);

        try {
            $badge = Badge::findOrFail($id);

            $data = [];
            if ($request->has('name')) {
                $data['name'] = trim((string) $request->input('name'));
            }
            if ($request->has('color')) {
                $data['color'] = trim((string) $request->input('color'));
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }
            if ($request->has('sort')) {
                $data['sort'] = (int) $request->input('sort');
            }

            $badge->update($data);

            return response()->json([
                'status' => 'success',
                'message' => '徽章更新成功',
                'data' => $badge,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 切換徽章啟用/停用狀態
     */
    public function toggleStatus($id)
    {
        try {
            $badge = Badge::findOrFail($id);
            $badge->status = $badge->status === 1 ? 0 : 1;
            $badge->save();

            return response()->json([
                'status' => 'success',
                'message' => $badge->status === 1 ? '已啟用徽章' : '已停用徽章',
                'data' => $badge,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 刪除徽章
     */
    public function destroy($id)
    {
        try {
            $badge = Badge::findOrFail($id);
            $badge->articles()->detach();
            $badge->delete();

            return response()->json([
                'status' => 'success',
                'message' => '徽章刪除成功',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
