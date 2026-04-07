<?php

namespace App\Support;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class ArticlePasswordCache
{
    public function cookieName(): string
    {
        return 'article_password_cache_id';
    }

    public function isVerified(Request $request, Article $article): bool
    {
        if ((int) $article->status !== 2) {
            return false;
        }

        $verifiedArticles = $this->getVerifiedArticles($request);
        $articleId = (string) $article->id;
        $currentSignature = $this->signature($article);

        if (($verifiedArticles[$articleId] ?? null) === $currentSignature) {
            return true;
        }

        if (array_key_exists($articleId, $verifiedArticles)) {
            unset($verifiedArticles[$articleId]);
            $this->storeVerifiedArticles($this->resolveClientId($request), $verifiedArticles);
        }

        return false;
    }

    public function remember(Request $request, Article $article): string
    {
        $clientId = $this->resolveClientId($request) ?? (string) Str::uuid();

        if ((int) $article->status !== 2) {
            return $clientId;
        }

        $verifiedArticles = $this->getVerifiedArticles($request);
        $verifiedArticles[(string) $article->id] = $this->signature($article);

        $this->storeVerifiedArticles($clientId, $verifiedArticles);

        return $clientId;
    }

    public function cacheKeyForClient(string $clientId): string
    {
        return "verified_article_passwords:{$clientId}";
    }

    private function getVerifiedArticles(Request $request): array
    {
        $clientId = $this->resolveClientId($request);

        if (!$clientId) {
            return [];
        }

        $verifiedArticles = Cache::get(
            $this->cacheKeyForClient($clientId),
            []
        );

        return is_array($verifiedArticles) ? $verifiedArticles : [];
    }

    private function storeVerifiedArticles(?string $clientId, array $verifiedArticles): void
    {
        if (!$clientId) {
            return;
        }

        Cache::put(
            $this->cacheKeyForClient($clientId),
            $verifiedArticles,
            now()->addDays(1)
        );
    }

    private function resolveClientId(Request $request): ?string
    {
        $clientId = $request->cookie($this->cookieName());

        if (!is_string($clientId) || $clientId === '') {
            return null;
        }

        return $clientId;
    }

    private function signature(Article $article): string
    {
        return hash('sha256', implode('|', [
            (string) $article->id,
            (string) $article->password,
            (string) $article->updated_at,
        ]));
    }
}
