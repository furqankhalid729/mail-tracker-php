<?php
/**
 * Approval state for one lead row (leads list, pending tasks), shown under the status picker.
 * Expects $l (lead row with approval_status, approver_name, approval_at, approval_note).
 * Super Admins get one-click Approve / Disapprove while the lead is awaiting approval.
 */
if (empty($l['approval_status'])) {
    return;
}
?>
<div class="mt-1 flex flex-wrap items-center gap-1">
    <?= lead_approval_badge($l) ?>
    <?php if ($l['approval_status'] === 'pending' && allowed('leads.approve')): ?>
        <form method="post" action="<?= e(url('leads/actions.php')) ?>" class="inline-flex gap-1">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <button name="action" value="approve" class="inline-flex items-center gap-0.5 rounded-md bg-emerald-600 px-1.5 py-0.5 text-[11px] font-semibold text-white hover:bg-emerald-700" title="Approve"><?= icon('check', 'h-3 w-3') ?> Approve</button>
            <button name="action" value="disapprove" class="inline-flex items-center gap-0.5 rounded-md bg-white px-1.5 py-0.5 text-[11px] font-semibold text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-50" title="Disapprove"><?= icon('x', 'h-3 w-3') ?> Disapprove</button>
        </form>
    <?php endif; ?>
</div>
