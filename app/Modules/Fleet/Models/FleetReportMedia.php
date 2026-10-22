<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class FleetReportMedia extends Model
{
    protected $table = 'fleet_report_media';

    protected $fillable = ['fleet_report_id', 'media_type', 'file_disk', 'file_path', 'mime'];

    /** Short-lived link (staff and the customer app use the same signed route) */
    public function url(): string
    {
        return URL::temporarySignedRoute('fleet.report-media', now()->addMinutes(30), ['media' => $this->id]);
    }
}
