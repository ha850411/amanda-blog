<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2000/svg">
  <channel>
    <title>Amanda | 探店 | 美食 | 生活 | 開箱</title>
    <link>{{ url('/') }}</link>
    <description>Amanda 的探店、美食、生活與開箱紀錄</description>
    <language>zh-TW</language>
    <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>
    <generator>Amanda Blog AI Engine</generator>
    @foreach ($articles as $article)
      <item>
        <title><![CDATA[{{ $article->title }}]]></title>
        <link>{{ route('article', ['id' => $article->id]) }}</link>
        <guid isPermaLink="true">{{ route('article', ['id' => $article->id]) }}</guid>
        <pubDate>{{ $article->created_at ? $article->created_at->toRfc2822String() : now()->toRfc2822String() }}</pubDate>
        <dc:creator><![CDATA[Amanda]]></dc:creator>
        @foreach ($article->tags as $tag)
          <category><![CDATA[{{ $tag->name }}]]></category>
        @endforeach
        <description><![CDATA[{{ $article->excerpt }}]]></description>
        <content:encoded><![CDATA[{!! $article->content !!}]]></content:encoded>
      </item>
    @endforeach
  </channel>
</rss>
