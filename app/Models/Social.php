<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Social extends Model
{
    use HasFactory;

    protected $table = 'social';

    protected $primaryKey = 'id';

    protected $fillable = [
        'picture',
        'url',
        'status',
    ];
}
