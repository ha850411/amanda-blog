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
}
