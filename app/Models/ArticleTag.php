<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ArticleTag extends Model
{
    use HasFactory;

    protected $table = 'article_tag';

    protected $primaryKey = 'id';

    protected $fillable = [
        'article_id',
        'tag_id',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y/m/d H:i:s',
        'updated_at' => 'datetime:Y/m/d H:i:s',
    ];
}
