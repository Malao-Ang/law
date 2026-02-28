<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationAmendment extends Model
{
    protected $fillable = [
        'regulation_id',
        'amendment_regulation_id',
        'section_id',
        'amendment_type',
        'old_content_html',
        'new_content_html',
        'amendment_date',
        'gazette_reference',
    ];

    protected $casts = [
        'amendment_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function regulation()
    {
        return $this->belongsTo(Regulation::class);
    }

    public function amendmentRegulation()
    {
        return $this->belongsTo(Regulation::class, 'amendment_regulation_id');
    }

    public function section()
    {
        return $this->belongsTo(RegulationSection::class);
    }
}
