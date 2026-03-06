<?php

namespace App\Http\Controllers\Api;

use App\Models\Tag;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    /**
    * 取得標籤樹（主選單 + 子選單）
    */
    public function index()
    {
        return response()->json([
            'data' => $this->getTagTree(),
        ]);
    }

    /**
     * 取得完整標籤樹
     */
    private function getTagTree()
    {
        return Tag::where('parent_id', 0)
            ->orderBy('sort', 'asc')
            ->with('children')
            ->get();
    }

    /**
     * 新增標籤（主選單 + 子選單一次建立）
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:300',
            'children' => 'nullable|array',
            'children.*' => 'string|max:300',
        ]);

        DB::beginTransaction();
        try {
            // 計算新的主選單 sort 值
            $maxSort = Tag::where('parent_id', 0)->max('sort') ?? 0;

            $parent = Tag::create([
                'name' => $request->name,
                'parent_id' => 0,
                'sort' => $maxSort + 1,
            ]);

            // 建立子選單
            if ($request->children && count($request->children) > 0) {
                foreach ($request->children as $index => $childName) {
                    Tag::create([
                        'name' => $childName,
                        'parent_id' => $parent->id,
                        'sort' => $index + 1,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '新增成功',
                'tags' => $this->getTagTree(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => '新增失敗：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新標籤（名稱 + 子選單）
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:300',
            'children' => 'nullable|array',
            'children.*' => 'string|max:300',
        ]);

        $tag = Tag::findOrFail($id);

        // 僅允許編輯主選單
        if ($tag->parent_id !== 0) {
            return response()->json([
                'success' => false,
                'message' => '僅能編輯主選單',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $tag->update(['name' => $request->name]);

            // 取得目前的子標籤名稱
            $existingChildren = $tag->children->pluck('name')->toArray();
            $newChildren = $request->children ?? [];

            // 刪除被移除的子標籤
            $toDelete = array_diff($existingChildren, $newChildren);
            if (count($toDelete) > 0) {
                Tag::where('parent_id', $tag->id)
                    ->whereIn('name', $toDelete)
                    ->delete();
            }

            // 新增新的子標籤
            $toAdd = array_diff($newChildren, $existingChildren);
            $maxChildSort = Tag::where('parent_id', $tag->id)->max('sort') ?? 0;
            foreach ($toAdd as $childName) {
                $maxChildSort++;
                Tag::create([
                    'name' => $childName,
                    'parent_id' => $tag->id,
                    'sort' => $maxChildSort,
                ]);
            }

            // 依照新的順序重新排序所有子標籤
            foreach ($newChildren as $index => $childName) {
                Tag::where('parent_id', $tag->id)
                    ->where('name', $childName)
                    ->update(['sort' => $index + 1]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'tags' => $this->getTagTree(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => '更新失敗：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 刪除標籤（含子標籤）
     */
    public function destroy($id)
    {
        $tag = Tag::findOrFail($id);

        DB::beginTransaction();
        try {
            // 刪除子標籤
            Tag::where('parent_id', $tag->id)->delete();
            // 刪除主標籤
            $tag->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '刪除成功',
                'tags' => $this->getTagTree(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => '刪除失敗：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新標籤排序（交換兩個標籤的 sort 值）
     */
    public function updateSort(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:tag,id',
            'direction' => 'required|in:up,down',
        ]);

        $tag = Tag::findOrFail($request->id);
        $parentId = $tag->parent_id;

        if ($request->direction === 'up') {
            $swapTag = Tag::where('parent_id', $parentId)
                ->where('sort', '<', $tag->sort)
                ->orderBy('sort', 'desc')
                ->first();
        } else {
            $swapTag = Tag::where('parent_id', $parentId)
                ->where('sort', '>', $tag->sort)
                ->orderBy('sort', 'asc')
                ->first();
        }

        if (!$swapTag) {
            return response()->json([
                'success' => false,
                'message' => '已經是最' . ($request->direction === 'up' ? '前' : '後') . '了',
            ]);
        }

        $tempSort = $tag->sort;
        $tag->sort = $swapTag->sort;
        $swapTag->sort = $tempSort;

        $tag->save();
        $swapTag->save();

        return response()->json([
            'success' => true,
            'message' => '排序更新成功',
            'tags' => $this->getTagTree(),
        ]);
    }
}
