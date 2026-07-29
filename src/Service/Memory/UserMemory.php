<?php

namespace Zephyrisle\FlarumZaiBot\Service\Memory;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $value
 * @property float $importance
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserMemory extends AbstractModel
{
    protected $table = 'zai_user_memories';

    public $timestamps = true;

    protected $casts = [
        'importance' => 'float',
        'last_accessed_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'key',
        'value',
        'importance',
        'last_accessed_at',
    ];
}
