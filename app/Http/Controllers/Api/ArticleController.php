<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Article;
use Carbon\Carbon;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $page = $request->input('page', 1);
            $perpage = $request->input('perpage', 10);

            $query = Article::query()->with('tags');

            $articles = $query->orderBy('id', 'desc')
                ->when($request->input('start'), function ($query) use ($request) {
                    $query->where('created_at', '>=', Carbon::parse($request->input('start'))->startOfDay());
                })
                ->when($request->input('end'), function ($query) use ($request) {
                    $query->where('created_at', '<=', Carbon::parse($request->input('end'))->endOfDay());
                })
                ->when($request->input('status'), function ($query) use ($request) {
                    $query->where('status', $request->input('status'));
                })
                ->when($request->input('tag'), function ($query) use ($request) {
                    $query->whereHas('tags', function ($query) use ($request) {
                        $query->where('name', 'like', '%' . $request->input('tag') . '%');
                    });
                })
                ->when($request->input("tagId"), function ($query) use ($request) {
                    $query->whereHas('tags', function ($query) use ($request) {
                        $query->where('tag.id', $request->input('tagId'));
                    });
                })
                ->paginate($perpage, ['*'], 'page', $page);

            $data = $articles->items();

            if ($request->has("show_first_image")) {
                $data = array_map(function ($item) {
                    // 從 data->content 中找出第一個 <img> 標籤的 src 屬性值
                    preg_match('/<img.*?src=["\'](.*?)["\']/', $item->content, $matches);
                    $item->first_image = $matches[1] ?? null;
                    return $item;
                }, $data);
            }

            return response()->json([
                'status' => 'success',
                'data' => $data,
                'total' => $articles->total(),
                'current_page' => $articles->currentPage(),
                'per_page' => $articles->perPage(),
                'last_page' => $articles->lastPage(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function store(Request $request)
    {
        try {
            $article = DB::transaction(function () use ($request) {
                // 若有 id 則為修改模式
                if ($request->input("id")) {
                    $article = Article::find($request->input("id"));
                    $article->update([
                        'title' => $request->input("title", ""),
                        'content' => $request->input("content", ""),
                        'status' => $request->input("status", 1),
                        'password' => $request->input("password", ""),
                    ]);
                    // 刪除原本對應的 tag
                    $article->tags()->detach();
                } else {
                    $article = Article::create([
                        'title' => $request->input("title", ""),
                        'content' => $request->input("content", ""),
                        'status' => $request->input("status", 1),
                        'password' => $request->input("password", ""),
                    ]);
                }
                // 前端傳來的是物件陣列，需取出 id ['id' => 1, ...] -> [1, ...]
                $tagIds = collect($request->input("selectedTags", []))->pluck('id')->toArray();
                $article->tags()->attach($tagIds);

                return $article;
            });

            return response()->json([
                'status' => 'success',
                'message' => '文章新增成功',
                'data' => $article,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 刪除文章
     */
    public function destroy($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $article = Article::findOrFail($id);
                // 刪除文章與標籤的關聯
                $article->tags()->detach();
                // 刪除文章
                $article->delete();
            });
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
        return response()->noContent();
    }
}
