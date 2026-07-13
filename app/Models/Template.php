<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'body', 'style'])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;
}
