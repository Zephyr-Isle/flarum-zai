<?php

namespace Zephyrisle\FlarumZaiBot\Service\Memory;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $summary
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property float $importance
 * @property Carbon|null $created_at
 */
class InteractionEvent extends AbstractModel
{
    protected $table = 'zai_interaction_events';

    public $timestamps = false;

    protected $casts = [
        'importance' => 'float',
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'summary',
        'reference_type',
        'reference_id',
        'importance',
        'created_at',
    ];
}
