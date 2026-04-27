<?php

namespace App\Services;

use App\Events\FormSubmitted;
use Illuminate\Support\Facades\DB;

class SubmissionNotifier
{
    public static function submitted(
        string $appCode,
        int|string $wfFormId,
        string $docNo,
        int|string $ownerUserId,
        string $ownerEmail,
        ?string $ownerName = null,
        ?string $reviewUrl = null,
        int $attachmentsCount = 0,
        array $extra = []
    ): void {
        // ส่งอีเวนต์หลัง DB commit เท่านั้น
        DB::afterCommit(function () use (
            $appCode,
            $wfFormId,
            $docNo,
            $ownerUserId,
            $ownerEmail,
            $ownerName,
            $reviewUrl,
            $attachmentsCount,
            $extra
        ) {
            FormSubmitted::dispatch(
                $appCode,
                $wfFormId,
                $docNo,
                $ownerUserId,
                $ownerEmail,
                $ownerName,
                $reviewUrl,
                $attachmentsCount,
                $extra
            );
        });
    }
}
