                                     
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapView extends Model
{
    protected $fillable = ['key', 'data'];
    protected $casts = [
        'data' => 'array',   // so $mapView->data becomes an array
    ];
}
