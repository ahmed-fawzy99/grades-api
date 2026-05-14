<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class GradeResource extends JsonApiResource
{
    public $attributes = [
        'name',
        'subject',
        'score',
        'horizon_processed',
        'ago',
        'created_at',
        'updated_at',
    ];

    public function toType(Request $request): string
    {
        return 'grades';
    }
}
