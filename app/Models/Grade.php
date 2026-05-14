<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    /** @use HasFactory<\Database\Factories\GradeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'subject',
        'score',
        'horizon_processed',
    ];

    protected $casts = [
        'score' => 'int',
        'horizon_processed' => 'bool',
    ];
}
