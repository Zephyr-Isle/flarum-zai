<?php

namespace Ramon\Stickers\Models;

use Flarum\Database\AbstractModel;

/**
 * Test stub mirroring ram0ng1/stickers' Sticker model.
 *
 * Loaded only when the real ramon/stickers extension is NOT installed, so the
 * ZAI Bot sticker tools can be unit-tested against an in-memory SQLite DB.
 * The real model (vendor/ramon/stickers) declares exactly this table/namespace,
 * so the tools' class_exists() checks see the same class in both environments.
 */
class Sticker extends AbstractModel
{
    protected $table = 'stickers';

    protected $guarded = [];
}
