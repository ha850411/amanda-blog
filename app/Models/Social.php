<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Social extends Model
{
    use HasFactory;

    protected $table = 'social';

    protected $primaryKey = 'id';

    protected $fillable = [
        'icon',
        'url',
        'status',
    ];
}
