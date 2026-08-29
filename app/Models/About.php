<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
