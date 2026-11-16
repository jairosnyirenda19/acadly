<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Grid\BulkResult;
use Acadly\Kernel\Grid\GridColumn;
use Acadly\Kernel\Grid\GridDefinition;
use Acadly\Kernel\Grid\GridFilter;
use Acadly\Kernel\Grid\GridOption;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Actions\MessageActions;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Sent messages on the data grid (the approved design): every message of any day, with what happened to it. */
final class MessagesGrid implements GridDefinition
{
    public function __construct(
        private readonly Access $access,
        private readonly Messages $messages,
        private readonly MessageActions $actions,
    ) {}

    public function key(): string
    {
        return 'messages';
    }

    public function title(): string
    {
        return 'Sent messages';
    }

    public function query(User $viewer): Builder
    {
        $this->access->check($viewer, NotifyPermission::Manage);

        return DB::table('notification_deliveries as d')->where('d.channel', '!=', 'none')->select('d.*');
    }

    public function idColumn(): string
    {
        return 'd.id';
    }

    public function columns(): array
    {
        return [
            new GridColumn('at', 'Time', 'd.queued_at', always: true),
            new GridColumn('to', 'To', 'lower(d.recipient_name)'),
            new GridColumn('address', 'Phone or email', null, shown: false),
            new GridColumn('notice', 'Notice', 'd.topic_key'),
            new GridColumn('channel', 'Channel', 'd.channel'),
            new GridColumn('state', 'State', 'd.status'),
            new GridColumn('units', 'SMS units', 'd.units'),
        ];
    }

    public function filters(User $viewer): array
    {
        return [
            GridFilter::dates('day', 'Day', 'd.queued_at'),
            GridFilter::choice('channel', 'Channel', [
                new GridOption(Channel::Sms->value, 'SMS', fn (Builder $q): Builder => $q->where('d.channel', Channel::Sms->value)),
                new GridOption(Channel::Email->value, 'Email', fn (Builder $q): Builder => $q->where('d.channel', Channel::Email->value)),
            ]),
            GridFilter::choice('state', 'State', [
                new GridOption('delivered', 'Delivered', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Delivered->value)),
                new GridOption('sent', 'Sent', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Sent->value)),
                new GridOption('not_delivered', 'Not delivered', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Failed->value)),
                new GridOption('waiting', 'Waiting', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Queued->value)),
                new GridOption('not_reachable', 'Not reachable', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Suppressed->value)->where('d.reason', 'not_reachable')),
                new GridOption('over_allowance', 'Over the allowance', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Suppressed->value)->where('d.reason', 'quota')),
                new GridOption('not_sent', 'Not sent', fn (Builder $q): Builder => $q->where('d.status', DeliveryStatus::Suppressed->value)
                    ->where(fn (Builder $w): Builder => $w->whereNull('d.reason')->orWhereNotIn('d.reason', ['not_reachable', 'quota']))),
            ]),
        ];
    }

    public function search(Builder $query, string $text): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)).'%';
        $digits = (string) preg_replace('/\D/', '', $text);
        $query->where(function (Builder $q) use ($like, $digits): void {
            $q->whereRaw('lower(d.recipient_name) like ?', [$like])->orWhereRaw('lower(d.subject) like ?', [$like])->orWhereRaw('lower(d.recipient_email) like ?', [$like]);

            if (strlen($digits) >= 4) {
                $q->orWhere('d.recipient_phone', 'like', '%'.substr($digits, -9).'%');
            }
        });
    }

    public function defaultSort(): array
    {
        return ['at', 'desc'];
    }

    public function rows(User $viewer, array $rows): array
    {
        return array_map(fn (object $r): array => $r instanceof stdClass ? $this->messages->item($r) : [], $rows);
    }

    public function plain(array $row, string $column): string|int|null
    {
        $value = $row[$column] ?? null;

        return is_string($value) || is_int($value) ? $value : null;
    }

    public function canExport(User $viewer): bool
    {
        return $this->access->allows($viewer, NotifyPermission::Manage);
    }

    public function bulkActions(User $viewer): array
    {
        return [['key' => 'again', 'label' => 'Send again']];
    }

    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult
    {
        $result = new BulkResult;

        foreach (DB::table('notification_deliveries')->whereIn('id', $ids)->get(['id', 'recipient_name']) as $row) {
            try {
                $this->actions->again($viewer, Row::text($row, 'id'));
                $result->did();
            } catch (ValidationException $e) {
                $result->refuse(Row::text($row, 'id'), Row::maybeText($row, 'recipient_name') ?? '', $e->validator->errors()->first());
            }
        }

        return $result;
    }
}
