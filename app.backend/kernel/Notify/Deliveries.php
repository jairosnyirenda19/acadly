<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * What became of the notices about some records, for the screen that shows
 * those records: who was told, by which channel, and how it stands.
 */
final class Deliveries
{
    /**
     * @param  list<string>  $subjectIds
     * @return array<string, list<array{id: string, to: string, phone: string|null, email: string|null, channel: string, state: 'delivered'|'sent'|'accepted'|'not_delivered'|'waiting'|'not_reachable', at: string, again: bool}>> subject id => deliveries, oldest first
     */
    public function about(string $subjectType, array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('notification_deliveries')->where('subject_type', $subjectType)->whereIn('subject_id', $subjectIds)
            ->where('channel', '!=', 'none')->orderBy('queued_at')->get() as $row) {
            $status = Row::text($row, 'status');
            $channel = Row::text($row, 'channel');
            $state = match ($status) {
                DeliveryStatus::Delivered->value => 'delivered',
                DeliveryStatus::Sent->value => $channel === Channel::Email->value ? 'accepted' : 'sent',
                DeliveryStatus::Failed->value => 'not_delivered',
                DeliveryStatus::Queued->value => 'waiting',
                default => Row::maybeText($row, 'reason') === 'not_reachable' ? 'not_reachable' : null,
            };

            if ($state === null) {
                continue;
            }

            $out[Row::text($row, 'subject_id')][] = [
                'id' => Row::text($row, 'id'),
                'to' => Row::maybeText($row, 'recipient_name') ?? '',
                'phone' => Row::maybeText($row, 'recipient_phone'),
                'email' => Row::maybeText($row, 'recipient_email'),
                'channel' => $channel,
                'state' => $state,
                'at' => Row::maybeText($row, 'delivered_at') ?? Row::maybeText($row, 'settled_at') ?? Row::text($row, 'queued_at'),
                'again' => Row::maybeText($row, 'resent_from') !== null,
            ];
        }

        return $out;
    }
}
