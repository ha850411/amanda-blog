<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tag extends Model
{
    use HasFactory;

    protected $table = 'tag';

    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'parent_id',
        'sort',
    ];

    public function children()
    {
        return $this->hasMany(Tag::class, 'parent_id', 'id')->orderBy('sort', 'asc');
    }
}
