<?php

namespace App\Http\Controllers\Admin;

use App\Models\Tag;

class TagController extends Controller
{
    public function tag()
    {
        $tags = Tag::where('parent_id', 0)
            ->orderBy('sort', 'asc')
            ->with('children')
            ->get();

        return view('admin.tag')->with([
            'active' => 'tag',
            'tags' => $tags,
        ]);
    }
}
