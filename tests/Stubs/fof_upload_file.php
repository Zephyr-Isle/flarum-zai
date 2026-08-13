<?php

namespace FoF\Upload;

use Flarum\Database\AbstractModel;

/**
 * Test stub mirroring FriendsOfFlarum/upload's File model.
 *
 * Loaded only when the real fof/upload extension is NOT installed, so the ZAI Bot
 * vision image proxy (VisionImageController) can be unit-tested against an
 * in-memory SQLite DB. Mirrors the 1.x schema (uuid column) — see tests/bootstrap.php.
 */
class File extends AbstractModel
{
    protected $table = 'fof_upload_files';

    protected $guarded = [];

    protected $casts = [
        'hidden' => 'boolean',
    ];
}
