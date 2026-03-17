<?php echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ url('/') }}</loc>
        <lastmod>{{ $articles->first() ? $articles->first()->updated_at->tz('UTC')->toAtomString() : now()->tz('UTC')->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    @foreach ($tags as $tag)
    <url>
        <loc>{{ url('/tag/' . $tag->id) }}</loc>
        <lastmod>{{ $tag->updated_at ? \Carbon\Carbon::parse($tag->updated_at)->tz('UTC')->toAtomString() : now()->tz('UTC')->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    @endforeach
    @foreach ($articles as $article)
    <url>
        <loc>{{ url('/article/' . $article->id) }}</loc>
        <lastmod>{{ $article->updated_at->tz('UTC')->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    @endforeach
</urlset>
