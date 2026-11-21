<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['store_id', 'parent_id', 'name', 'name_local', 'scope', 'sort_order'])]
class Category extends Model
{
    use SoftDeletes;
}
