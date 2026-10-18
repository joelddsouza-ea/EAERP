<?php

namespace App\Modules\CustomerApp\Models;

use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * A document the customer keeps in the app (insurance, PUC, RC, licence), with its expiry.
 */
class CustomerDocument extends Model
{
    public const TYPES = [
        'insurance' => 'Insurance policy',
        'puc' => 'PUC certificate',
        'rc' => 'Registration certificate (RC)',
        'licence' => 'Driving licence',
        'other' => 'Other',
    ];

    protected $fillable = ['customer_id', 'vehicle_id', 'type', 'title', 'number', 'expiry_date', 'file_disk', 'file_path', 'mime'];

    protected $casts = ['expiry_date' => 'date'];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Short-lived link to the file (staff and the customer app use the same signed route) */
    public function fileUrl(): string
    {
        return URL::temporarySignedRoute('customer-app.document', now()->addMinutes(30), ['document' => $this->id]);
    }
}
