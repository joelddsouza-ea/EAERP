<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Photo of a supplier's bill (no line) or of a part as received (its line). Internal only.
 */
class GoodsReceiptMedia extends Model
{
    protected $table = 'goods_receipt_media';

    protected $fillable = ['goods_receipt_id', 'goods_receipt_line_id', 'disk', 'file_path', 'thumbnail_path', 'mime', 'size_bytes', 'uploaded_by'];

    public function url(bool $thumbnail = false): ?string
    {
        if ($thumbnail && !$this->thumbnail_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'workshop.grn-media.stream',
            now()->addMinutes((int) config('workshop.job_sheets.media_url_ttl', 30)),
            array_filter(['media' => $this->id, 'thumb' => $thumbnail ? 1 : null])
        );
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'line_id' => $this->goods_receipt_line_id,
            'url' => $this->url(),
            'thumbnail_url' => $this->url(true) ?? $this->url(),
            'is_pdf' => $this->mime === 'application/pdf',
        ];
    }
}
