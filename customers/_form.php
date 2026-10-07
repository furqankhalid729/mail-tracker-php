<?php
defined('APP_ROOT') || exit;
/**
 * Shared customer form. Expects: $customer (array, may be empty), $customerTags (int[]), $allTags, $errors.
 */
$v = fn(string $k) => old($k, $customer[$k] ?? '');
$custom = [];
foreach ((json_decode((string) ($customer['custom_fields'] ?? ''), true) ?: []) as $k => $val) {
    $custom[] = ['key' => (string) $k, 'value' => is_scalar($val) ? (string) $val : json_encode($val)];
}
if (isset($_SESSION['_old']['cf_key'])) {
    $custom = [];
    foreach ((array) $_SESSION['_old']['cf_key'] as $i => $k) {
        $custom[] = ['key' => (string) $k, 'value' => (string) ($_SESSION['_old']['cf_value'][$i] ?? '')];
    }
}
$selectedTags = array_map('intval', (array) old('tags', $customerTags));
?>
<?= csrf_field() ?>
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Contact details</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="first_name">First name</label><input class="input" id="first_name" name="first_name" value="<?= e($v('first_name')) ?>" maxlength="100"></div>
                <div><label class="label" for="last_name">Last name</label><input class="input" id="last_name" name="last_name" value="<?= e($v('last_name')) ?>" maxlength="100"></div>
                <div class="sm:col-span-2">
                    <label class="label" for="email">Email <span class="text-red-500">*</span></label>
                    <input class="input" id="email" type="email" name="email" value="<?= e($v('email')) ?>" required maxlength="190">
                    <?= field_error($errors, 'email') ?>
                </div>
                <div><label class="label" for="company">Company</label><input class="input" id="company" name="company" value="<?= e($v('company')) ?>" maxlength="190"></div>
                <div><label class="label" for="job_title">Job title</label><input class="input" id="job_title" name="job_title" value="<?= e($v('job_title')) ?>" maxlength="150"></div>
                <div><label class="label" for="phone">Phone</label><input class="input" id="phone" name="phone" value="<?= e($v('phone')) ?>" maxlength="60"></div>
                <div>
                    <label class="label" for="website">Website</label><input class="input" id="website" name="website" value="<?= e($v('website')) ?>" placeholder="example.com">
                    <?= field_error($errors, 'website') ?>
                </div>
                <div><label class="label" for="country">Country</label><input class="input" id="country" name="country" value="<?= e($v('country')) ?>" maxlength="100"></div>
                <div><label class="label" for="source">Source</label><input class="input" id="source" name="source" value="<?= e($v('source')) ?>" placeholder="LinkedIn, referral…" maxlength="100"></div>
                <div class="sm:col-span-2"><label class="label" for="notes">Notes</label><textarea class="input" id="notes" name="notes" rows="3"><?= e($v('notes')) ?></textarea></div>
            </div>
        </div>

        <div class="card" x-data='{ fields: <?= json_encode($custom ?: [], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) ?> }'>
            <div class="card-header">
                <div><h2 class="card-title">Custom fields</h2><p class="text-xs text-slate-500">Usable in emails as {{fieldName}}.</p></div>
                <button type="button" class="btn-secondary btn-sm" @click="fields.push({ key: '', value: '' })"><?= icon('plus', 'h-3.5 w-3.5') ?> Add field</button>
            </div>
            <div class="card-body space-y-2">
                <template x-for="(f, i) in fields" :key="i">
                    <div class="flex gap-2">
                        <input class="input w-1/3" name="cf_key[]" x-model="f.key" placeholder="industry" pattern="[A-Za-z0-9_.-]+" title="Letters, numbers, _ . -">
                        <input class="input flex-1" name="cf_value[]" x-model="f.value" placeholder="SaaS">
                        <button type="button" class="btn-icon" @click="fields.splice(i, 1)"><?= icon('x', 'h-4 w-4') ?></button>
                    </div>
                </template>
                <p x-show="fields.length === 0" class="text-sm text-slate-400">No custom fields.</p>
                <?= field_error($errors, 'custom_fields') ?>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Status</h2></div>
            <div class="card-body space-y-4">
                <div>
                    <label class="label" for="crm_status">CRM stage</label>
                    <select class="input" id="crm_status" name="crm_status">
                        <?php foreach (CRM_STATUSES as $s): ?><option <?= $v('crm_status') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="status">Email status</label>
                    <select class="input" id="status" name="status">
                        <?php foreach (CUSTOMER_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= ($v('status') ?: 'active') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                    </select>
                    <p class="help">Unsubscribed and bounced contacts never receive campaign emails.</p>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Tags</h2><a href="<?= e(url('tags/index.php')) ?>" class="text-xs font-medium text-indigo-600">Manage</a></div>
            <div class="card-body">
                <?php if (!$allTags): ?><p class="text-sm text-slate-400">No tags yet.</p><?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($allTags as $t): ?>
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-1 text-xs has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                            <input type="checkbox" class="checkbox !h-3.5 !w-3.5" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $selectedTags, true) ? 'checked' : '' ?>>
                            <span class="h-2 w-2 rounded-full" style="background: <?= e($t['color']) ?>"></span><?= e($t['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button class="btn-primary w-full py-2.5">Save customer</button>
    </div>
</div>
