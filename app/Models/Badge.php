<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;

    protected $table = 'badge';

    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'color',
        'status',
        'sort',
    ];

    protected $casts = [
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime:Y/m/d H:i:s',
        'updated_at' => 'datetime:Y/m/d H:i:s',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }

    public function articles()
    {
        return $this->belongsToMany(Article::class, 'article_badge', 'badge_id', 'article_id');
    }
}
