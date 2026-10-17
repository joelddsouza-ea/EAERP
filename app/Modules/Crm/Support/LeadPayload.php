<?php

namespace App\Modules\Crm\Support;

use App\Core\Models\Branch;

/**
 * Turns what an ad platform (or a connector like Pabbly / Zapier / Make, or a website
 * form) posts into lead fields. Understands:
 *  - plain JSON / form fields (name, phone, email, car, requirement, campaign, utm_*…);
 *  - Google Ads lead forms ("user_column_data": [{column_id, string_value}]);
 *  - Meta Lead Ads field data ("field_data": [{name, values: []}]).
 */
class LeadPayload
{
    public static function parse(array $in): array
    {
        $fields = self::flatten($in);

        $pick = function (array $keys) use ($fields): ?string {
            foreach ($keys as $k) {
                if (isset($fields[$k]) && trim((string) $fields[$k]) !== '') {
                    return trim((string) $fields[$k]);
                }
            }

            return null;
        };

        $name = $pick(['full_name', 'name', 'customer_name', 'your_name'])
            ?? trim(($pick(['first_name', 'given_name']) ?? '') . ' ' . ($pick(['last_name', 'family_name']) ?? '')) ?: null;

        $tracking = array_filter([
            'utm_source' => $pick(['utm_source']),
            'utm_medium' => $pick(['utm_medium']),
            'utm_campaign' => $pick(['utm_campaign']),
            'utm_content' => $pick(['utm_content']),
            'ad' => $pick(['ad_name', 'ad_id', 'creative_id']),
            'adset' => $pick(['adset_name', 'adset_id', 'adgroup_id', 'ad_group_id']),
            'form' => $pick(['form_name', 'form_id']),
            'platform_lead_id' => $pick(['lead_id', 'leadgen_id']),
            'gclid' => $pick(['gcl_id', 'gclid']),
        ]);

        $branchCode = $pick(['branch', 'branch_code', 'location']);
        $branchId = $branchCode ? Branch::where('code', $branchCode)->orWhere('name', $branchCode)->value('id') : null;

        return array_filter([
            'name' => $name,
            'phone' => $pick(['phone', 'phone_number', 'mobile', 'mobile_number', 'whatsapp', 'contact_number']),
            'email' => $pick(['email', 'email_address', 'work_email']),
            'city' => $pick(['city', 'area', 'locality']),
            'car_text' => $pick(['car', 'car_model', 'vehicle', 'vehicle_model', 'which_car_do_you_own', 'car_make_and_model']),
            'registration_no' => $pick(['registration_no', 'car_number', 'vehicle_number']),
            'requirement' => $pick(['requirement', 'message', 'notes', 'service', 'service_required', 'what_service_do_you_need', 'comments']),
            'campaign' => $pick(['campaign_name', 'campaign', 'utm_campaign', 'campaign_id']),
            'tracking' => $tracking ?: null,
            'branch_id' => $branchId,
            'referral_code' => $pick(['referral_code', 'ref']),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * All fields as lower_snake keys => first value.
     */
    protected static function flatten(array $in): array
    {
        $out = [];
        $key = fn ($k) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $k)), '_');

        foreach ($in as $k => $v) {
            if (is_scalar($v)) {
                $out[$key($k)] = $v;
            }
        }

        // Google Ads lead form extension
        foreach ((array) ($in['user_column_data'] ?? []) as $col) {
            $id = $key($col['column_id'] ?? $col['column_name'] ?? '');
            $out[$id] = $col['string_value'] ?? ($col['value'] ?? null);
        }

        // Meta Lead Ads (graph API / most connectors)
        foreach ((array) ($in['field_data'] ?? []) as $field) {
            $values = (array) ($field['values'] ?? []);
            $out[$key($field['name'] ?? '')] = $values[0] ?? null;
        }

        // Nested "lead" / "data" objects some connectors send
        foreach (['lead', 'data', 'fields'] as $nest) {
            if (isset($in[$nest]) && is_array($in[$nest])) {
                $out += self::flatten($in[$nest]);
            }
        }

        return $out;
    }
}
