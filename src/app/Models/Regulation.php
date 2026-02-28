<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Regulation extends Model
{
    protected $fillable = [
        'title',
        'regulation_type',
        'enacted_date',
        'effective_date',
        'status',
        'full_html',
        'original_filename',
        'file_type',
        'created_by',
    ];

    protected $casts = [
        'enacted_date' => 'date',
        'effective_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(RegulationSection::class);
    }

    public function rootSections(): HasMany
    {
        return $this->hasMany(RegulationSection::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(RegulationCategory::class, 'regulation_category');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(RegulationAmendment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
