<?php

namespace OzanKurt\Shield\Models\Lookups;

use Illuminate\Database\Eloquent\Model;

abstract class Lookup extends Model
{
    protected $fillable = ['name', 'label', 'description', 'sort_order', 'meta'];

    protected $casts = [
        'meta' => 'json',
        'sort_order' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }

        parent::__construct($attributes);
    }
}
