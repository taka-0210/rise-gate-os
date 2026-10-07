<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class PostmarkAccountWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $config = config('account_delivery.webhook');
        if (! $config['enabled'] || ! $config['user'] || ! $config['password'] || ! $config['stream'] || ! $config['server_id'] || ! $config['allowed_cidrs']) {
            return response('', 503);
        }
        if (! $request->isSecure()) {
            return response('', 403);
        }
        if (! hash_equals((string) $config['user'], (string) $request->getUser())
            || ! hash_equals((string) $config['password'], (string) $request->getPassword())) {
            return response('', 401);
        }
        // Use the actual peer; forwarded headers cannot broaden the allowlist.
        if (! IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), $config['allowed_cidrs'])) {
            return response('', 403);
        }
        if (strlen($request->getContent()) > 65536 || ! $request->isJson()) {
            return response('', 422);
        }
        $data = $request->json()->all();
        $types = ['Delivery' => 'delivered', 'Bounce' => 'bounced', 'SpamComplaint' => 'complained'];
        $type = $data['RecordType'] ?? null;
        if (! is_string($type) || ! isset($types[$type])
            || ! is_string($data['MessageID'] ?? null) || strlen($data['MessageID']) > 190
            || $data['MessageID'] === '' || ($data['MessageStream'] ?? null) !== $config['stream']
            || ! is_int($data['ServerID'] ?? null) || (string) $data['ServerID'] !== (string) $config['server_id']
            || (isset($data['ID']) && ! is_int($data['ID']))) {
            return response('', 422);
        }
        $date = $data[$type === 'Delivery' ? 'DeliveredAt' : 'BouncedAt'] ?? null;
        try {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/', $date)) {
                return response('', 422);
            }
            $at = CarbonImmutable::parse($date)->setTimezone('Asia/Tokyo');
        } catch (Throwable) {
            return response('', 422);
        }
        try {
            return DB::transaction(function () use ($data, $type, $types, $at) {
                $row = DB::table('account_mail_deliveries')->where('provider', 'postmark')
                    ->where('provider_message_id', $data['MessageID'])->lockForUpdate()->first();
                $deliveryId = $data['Metadata']['delivery_id'] ?? null;
                if (! $row && is_string($deliveryId)) {
                    $row = DB::table('account_mail_deliveries')->where('provider', 'postmark')
                        ->where('public_id', $deliveryId)->whereNull('provider_message_id')
                        ->whereIn('status', ['sending', 'delivery_unknown'])->lockForUpdate()->first();
                    if ($row) {
                        DB::table('account_mail_deliveries')->where('id', $row->id)->update([
                            'provider_message_id' => $data['MessageID'], 'updated_at' => now(),
                        ]);
                    }
                }
                if (! $row) {
                    // Acceptance may not yet have committed. Preserve the event via provider retry.
                    return response('', 503);
                }
                $key = hash('sha256', 'postmark|'.$type.'|'.$data['MessageID'].'|'.($data['ID'] ?? $at->toIso8601String()));
                $inserted = DB::table('account_mail_provider_events')->insertOrIgnore([
                    'delivery_id' => $row->id, 'event_key' => $key, 'type' => $types[$type],
                    'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $rank = ['accepted' => 0, 'delivered' => 1, 'bounced' => 2, 'complained' => 3];
                if ($inserted && ($rank[$types[$type]] > ($rank[$row->status] ?? -1))) {
                    DB::table('account_mail_deliveries')->where('id', $row->id)->update([
                        'status' => $types[$type], 'event_at' => $at, 'updated_at' => now(),
                    ]);
                }

                return response('', 200);
            });
        } catch (Throwable) {
            return response('', 503);
        }
    }
}
