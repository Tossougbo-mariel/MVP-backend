<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyTaskStatus extends Model
{
    protected $fillable = [
        'agency_id', 'key', 'label', 'color', 'position', 'is_terminal',
    ];

    protected $casts = [
        'is_terminal' => 'boolean',
        'position' => 'integer',
    ];

    protected $attributes = [
        'color' => '#056cf2',
        'position' => 0,
        'is_terminal' => false,
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** Clés interdites car elles clasheraient avec le vocabulaire interne. */
    public const RESERVED_KEYS = ['archive', 'archived', 'supprime', 'supprimee'];

    public static function isReservedKey(string $key): bool
    {
        return in_array(mb_strtolower($key), self::RESERVED_KEYS, true);
    }
}
