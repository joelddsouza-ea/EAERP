<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Services\PostingService;
use Illuminate\Support\Facades\DB;

/**
 * Posts a source to the books once the change that touched it is committed (a bill is
 * saved several times while it is built; each run of sync() after the first is a no-op).
 * A posting problem never undoes the business action: it is reported, and
 * `accounts:resync` brings the books back in step. In tests it is thrown.
 */
class PostingQueue
{
    public static function push(string $type, ?int $id): void
    {
        // Accounts switched off: nothing goes on the books (resync posts it later)
        if (!$id || !\App\Core\Support\Modules::accounts()) {
            return;
        }

        DB::afterCommit(function () use ($type, $id) {
            try {
                app(PostingService::class)->syncSource($type, $id);
            } catch (\Throwable $e) {
                if (app()->runningUnitTests()) {
                    throw $e;
                }
                report($e);
            }
        });
    }
}
