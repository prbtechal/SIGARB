<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Calibre extends Model
{
    protected $table = 'calibres';
    public $timestamps = false;
    protected $fillable = ['nome'];
}
