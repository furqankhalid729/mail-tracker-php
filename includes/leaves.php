<?php
declare(strict_types=1);

/**
 * Leave requests: every member (Closers included) can ask for leave and see their own requests.
 * Only roles with leaves.approve (Super Admin) see everyone's requests and approve or reject them.
 */

const LEAVE_TYPES = ['annual' => 'Annual', 'sick' => 'Sick', 'casual' => 'Casual', 'unpaid' => 'Unpaid', 'other' => 'Other'];
const LEAVE_STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

function leave_status_badge(?string $status): string
{
    $color = ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red', 'cancelled' => 'slate'][$status] ?? 'slate';
    return '<span class="badge badge-' . $color . '">' . e(LEAVE_STATUSES[$status] ?? (string) $status) . '</span>';
}

/** Inclusive calendar days between two Y-m-d dates; 0.5 for a half day. */
function leave_days(string $start, string $end, bool $halfDay): float
{
    if ($halfDay) {
        return 0.5;
    }
    return (float) ((new DateTime($start))->diff(new DateTime($end))->days + 1);
}

function format_leave_days(float|string $days): string
{
    $d = (float) $days;
    return rtrim(rtrim(number_format($d, 1), '0'), '.') . ' day' . ($d == 1.0 || $d == 0.5 ? '' : 's');
}

/** "Mar 3 – Mar 5, 2026", or a single date. */
function format_leave_range(string $start, string $end): string
{
    if ($start === $end) {
        return format_dt($start, 'D, M j, Y');
    }
    $sameYear = substr($start, 0, 4) === substr($end, 0, 4);
    return format_dt($start, $sameYear ? 'M j' : 'M j, Y') . ' – ' . format_dt($end, 'M j, Y');
}

/** Pending requests waiting for the current user to review (0 when they cannot approve). */
function leave_pending_count(): int
{
    if (!allowed('leaves.approve')) {
        return 0;
    }
    return (int) q_val("SELECT COUNT(*) FROM leave_requests WHERE workspace_id = ? AND status = 'pending'", [ws_id()]);
}
