<?php
/**
 * Inline status picker for one lead row (leads list, pending tasks). Expects $l (lead row) and $statuses;
 * set $hideDateBadge to leave out the follow-up date under it (when the page shows it elsewhere).
 * Statuses that require a date open a date field under the dropdown and save as soon as a date is picked.
 */
?>
<form method="post" action="<?= e(url('leads/actions.php')) ?>" x-data="{ needsDate: false }">
    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
    <select name="status_id" x-ref="status" class="input !w-auto !py-1 !pl-2 text-xs" aria-label="Status"
            @change="needsDate = $event.target.selectedOptions[0].dataset.date === '1'; if (needsDate) $nextTick(() => { $refs.date.focus(); try { $refs.date.showPicker() } catch (e) {} }); else $el.form.submit()"
            style="border-left: 4px solid <?= e($statuses[$l['status_id']]['color'] ?? '#cbd5e1') ?>">
        <?php if (!$l['status_id']): ?><option value="">No status</option><?php endif; ?>
        <?php foreach ($statuses as $sid => $s): ?><option value="<?= (int) $sid ?>" data-date="<?= $s['requires_date'] ? 1 : 0 ?>" <?= (int) $l['status_id'] === (int) $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <div x-show="needsDate" x-cloak class="mt-1 flex items-center gap-1">
        <input type="date" name="follow_up_on" x-ref="date" min="<?= date('Y-m-d') ?>" :required="needsDate" :disabled="!needsDate" class="input !w-auto !py-1 !px-2 text-xs" aria-label="Follow-up date"
               @change="if ($el.value && $el.checkValidity()) $el.form.submit()">
        <button type="button" class="btn-icon !px-1" aria-label="Cancel" @click="needsDate = false; $refs.status.value = '<?= (int) $l['status_id'] ?: '' ?>'">&times;</button>
    </div>
    <?php if ($l['follow_up_on'] && empty($hideDateBadge)): ?><div class="mt-1" x-show="!needsDate"><?= lead_follow_up_badge($l['follow_up_on']) ?></div><?php endif; ?>
</form>
