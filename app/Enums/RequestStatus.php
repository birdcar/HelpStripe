<?php

namespace App\Enums;

/**
 * The lifecycle of a helpdesk request, mirroring HelpSpot's status model.
 *
 * This is a *string-backed* enum: each case carries a string value that is
 * what actually gets stored in the `requests.status` column. Pairing a
 * backed enum with an Eloquent enum cast (see Request::casts()) means the
 * database stores plain strings while your PHP code always works with
 * type-safe enum instances — `$request->status === RequestStatus::Active`
 * instead of fragile string comparisons.
 */
enum RequestStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * Get the human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * Get the Flux badge color for this status.
     *
     * Centralizing presentation hints on the enum keeps every Blade view
     * that renders a status badge consistent: `<flux:badge :color="$status->color()">`.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Pending => 'amber',
            self::Resolved => 'green',
            self::Closed => 'zinc',
        };
    }

    /**
     * Statuses that count as "open" work for queue filtering.
     *
     * @return array<self>
     */
    public static function open(): array
    {
        return [self::Active, self::Pending];
    }
}
