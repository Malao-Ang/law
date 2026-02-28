<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectionReference extends Model
{
    protected $fillable = [
        'source_section_id',
        'target_section_id',
        'reference_type',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sourceSection()
    {
        return $this->belongsTo(RegulationSection::class, 'source_section_id');
    }

    public function targetSection()
    {
        return $this->belongsTo(RegulationSection::class, 'target_section_id');
    }
}
