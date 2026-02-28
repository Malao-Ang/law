<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationSection extends Model
{
    protected $fillable = [
        'regulation_id',
        'parent_id',
        'section_type',
        'section_number',
        'section_label',
        'content_html',
        'content_text',
        'sort_order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function regulation()
    {
        return $this->belongsTo(Regulation::class);
    }

    public function parent()
    {
        return $this->belongsTo(RegulationSection::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(RegulationSection::class, 'parent_id')->orderBy('sort_order');
    }

    public function sourceReferences()
    {
        return $this->hasMany(SectionReference::class, 'source_section_id');
    }

    public function targetReferences()
    {
        return $this->hasMany(SectionReference::class, 'target_section_id');
    }
}
