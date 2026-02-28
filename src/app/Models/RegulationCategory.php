<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'parent_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function parent()
    {
        return $this->belongsTo(RegulationCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(RegulationCategory::class, 'parent_id');
    }

    public function regulations()
    {
        return $this->belongsToMany(Regulation::class, 'regulation_category');
    }
}
