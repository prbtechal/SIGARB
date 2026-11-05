<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fabricante extends Model
{
    protected $table = 'fabricantes';
    public $timestamps = false;
    protected $fillable = ['nome'];
}
