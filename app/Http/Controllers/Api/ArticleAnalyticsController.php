<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\CloudflareAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ArticleAnalyticsController extends Controller
{
    public function __invoke(Request $request, CloudflareAnalyticsService $analytics): JsonResponse
    {
        $today = CarbonImmutable::today('Asia/Taipei');
        $validated = $request->validate([
            'start' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start', 'before_or_equal:'.$today->toDateString()],
        ]);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $validated['start'], 'Asia/Taipei');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $validated['end'], 'Asia/Taipei');
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > config('cloudflare.max_range_days')) {
            throw ValidationException::withMessages(['end' => '每次最多查詢 31 天，請縮小日期範圍。']);
        }

        if (! $analytics->configured()) {
            return response()->json([
                'status' => 'not_configured',
                'message' => '尚未連接 Cloudflare 分析資料。請設定帳戶 ID 與僅供讀取分析資料的 API Token。',
            ]);
        }

        $articles = Article::query()->select(['id', 'title', 'status', 'created_at'])->get();
        try {
            $metrics = $analytics->fetch($start, $end, $articles->modelKeys());
        } catch (Throwable) {
            return response()->json([
                'status' => 'unavailable',
                'message' => '暫時無法取得 Cloudflare 資料。請稍後重試；若持續發生，請確認 Token 的 Account Analytics 讀取權限、帳戶 ID，以及日期是否在資料保留範圍內。',
            ], 503);
        }

        $total = array_sum($metrics['by_article']);
        $rows = $articles->map(function (Article $article) use ($metrics, $total) {
            $views = $metrics['by_article'][$article->id] ?? 0;

            return [
                'id' => $article->id,
                'title' => $article->title,
                'status' => (int) $article->status,
                'page_views' => $views,
                'share' => $total > 0 ? round($views / $total * 100, 1) : 0,
                'url' => route('article', $article->id),
                'edit_url' => route('admin.article.edit', $article->id),
            ];
        })->sort(fn ($a, $b) => $b['page_views'] <=> $a['page_views'] ?: $b['id'] <=> $a['id'])->values();

        return response()->json([
            'status' => 'ready',
            'data' => [
                'source' => 'Cloudflare Web Analytics',
                'timezone' => 'Asia/Taipei',
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'fetched_at' => $metrics['fetched_at'],
                'through' => $metrics['through'],
                'sampled' => $metrics['sampled'],
                'summary' => [
                    'page_views' => $total,
                    'viewed_articles' => $rows->where('page_views', '>', 0)->count(),
                    'total_articles' => $rows->count(),
                    'average_daily_views' => round($total / $days, 1),
                ],
                'daily' => collect($metrics['daily'])->map(fn ($views, $date) => [
                    'date' => $date, 'page_views' => $views,
                ])->values(),
                'articles' => $rows,
            ],
        ]);
    }
}
