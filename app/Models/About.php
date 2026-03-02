<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class About extends Model
{
    use HasFactory;

    protected $table = 'about';

    protected $primaryKey = 'id';

    protected $fillable = [
        'title',
        'sub_title',
        'description',
        'picture',
        'updated_at',
    ];
}
