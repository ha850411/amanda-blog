<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\ArticlePasswordCache;
use Carbon\Carbon;

class ArticleController extends Controller
{
    public function index(Request $request, ArticlePasswordCache $articlePasswordCache)
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

            $showFirstImage = $request->boolean('show_first_image');
            $data = collect($articles->items())
                ->map(function (Article $article) use ($request, $articlePasswordCache, $showFirstImage) {
                    $isPasswordVerified = $articlePasswordCache->isVerified($request, $article);

                    return [
                        'id' => $article->id,
                        'title' => $article->title,
                        'status' => $article->status,
                        'created_at' => $article->created_at?->format('Y/m/d H:i:s'),
                        'updated_at' => $article->updated_at?->format('Y/m/d H:i:s'),
                        'tags' => $article->tags->map(fn ($tag) => [
                            'id' => $tag->id,
                            'name' => $tag->name,
                        ])->values()->all(),
                        'is_password_verified' => $isPasswordVerified,
                        'first_image' => $showFirstImage
                            ? $this->resolveFirstImage($article, $isPasswordVerified)
                            : null,
                    ];
                })
                ->values()
                ->all();

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

    public function verify(Request $request, int $id, ArticlePasswordCache $articlePasswordCache)
    {
        $article = Article::query()
            ->with('tags')
            ->findOrFail($id);

        if ((int) $article->status !== 2) {
            return response()->json([
                'status' => 'success',
                'data' => $this->buildVerifiedArticlePayload($article),
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (!hash_equals((string) $article->password, (string) $validated['password'])) {
            return response()->json([
                'status' => 'error',
                'message' => '密碼錯誤',
            ], 422);
        }

        $clientId = $articlePasswordCache->remember($request, $article);

        return response()->json([
            'status' => 'success',
            'data' => $this->buildVerifiedArticlePayload($article),
        ])->cookie(cookie()->make(
            $articlePasswordCache->cookieName(),
            $clientId,
            60 * 24,
            '/',
            null,
            false,
            true,
            false,
            'lax'
        ));
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

    private function buildVerifiedArticlePayload(Article $article): array
    {
        return [
            'id' => $article->id,
            'content' => $article->content,
            'first_image' => $this->resolveFirstImage($article, true),
            'is_password_verified' => true,
        ];
    }

    private function resolveFirstImage(Article $article, bool $isPasswordVerified): ?string
    {
        if ((int) $article->status === 2 && !$isPasswordVerified) {
            return null;
        }

        preg_match('/<img.*?src=["\'](.*?)["\']/', (string) $article->content, $matches);

        return $matches[1] ?? null;
    }
}
