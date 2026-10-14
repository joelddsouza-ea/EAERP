<?php

namespace Database\Seeders\Support;

/**
 * Turns the Remarks-column markers on the paper job sheets into activity rules.
 *
 *   "***"                 -> media required (default photo or video)
 *   "Direct" / blank      -> tick only, no media
 *   "NA Button"           -> may be marked not applicable
 *   combinations, e.g. "NA Button ***", "Direct & NA Button", "NA Button  ****"
 */
class MarkerParser
{
    /**
     * @return array{media_requirement: string, na_allowed: bool, unknown: bool}
     */
    public static function parse(?string $marker, string $starDefault = 'photo_or_video'): array
    {
        $normalized = strtoupper(preg_replace('/\s+/', ' ', trim((string) $marker)));

        $media = preg_match('/\*{3,}/', $normalized) ? $starDefault : 'none';
        $na = (bool) preg_match('/\bNA\s*BUTTON\b/', $normalized);

        // Anything left after removing the known tokens is unexpected
        $rest = trim(preg_replace(['/\*+/', '/\bNA\s*BUTTON\b/', '/\bDIRECT\b/', '/&/'], '', $normalized));

        return [
            'media_requirement' => $media,
            'na_allowed' => $na,
            'unknown' => $rest !== '',
        ];
    }
}
