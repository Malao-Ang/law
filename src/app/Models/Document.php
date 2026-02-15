<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'title',
        'content',
        'original_filename',
        'file_type'
    ];

    protected $casts = [
        'content' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}
