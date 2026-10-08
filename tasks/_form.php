<?php
defined('APP_ROOT') || exit;
/**
 * Shared task form. Expects: $task (array, may be empty), $users, $campaigns, $linkedCustomer (?array), $errors.
 */
$v = fn(string $k, mixed $default = '') => old($k, $task[$k] ?? $default);
$canAssign = allowed('tasks.manage_all');
$assignee = (int) old('assigned_to', array_key_exists('assigned_to', $task) ? $task['assigned_to'] : user_id());
?>
<?= csrf_field() ?>
<div class="grid gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Task</h2></div>
        <div class="card-body space-y-4">
            <div>
                <label class="label" for="title">Title <span class="text-red-500">*</span></label>
                <input class="input" id="title" name="title" value="<?= e($v('title')) ?>" required maxlength="200" placeholder="Follow up with leads from the Shopify campaign">
                <?= field_error($errors, 'title') ?>
            </div>
            <div>
                <label class="label" for="description">Description</label>
                <textarea class="input" id="description" name="description" rows="8" placeholder="What needs to be done, links, acceptance criteria…"><?= e($v('description')) ?></textarea>
                <?= field_error($errors, 'description') ?>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <div class="card-body space-y-4">
                <div>
                    <label class="label" for="assigned_to">Assign to</label>
                    <?php if ($canAssign): ?>
                        <select class="input" id="assigned_to" name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $assignee ? 'selected' : '' ?>><?= e($u['name']) ?><?= (int) $u['id'] === user_id() ? ' (you)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input class="input bg-slate-50" value="<?= e(current_user()['name']) ?> (you)" disabled>
                        <p class="help">Managers can assign tasks to other people.</p>
                    <?php endif; ?>
                    <?= field_error($errors, 'assigned_to') ?>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="priority">Priority</label>
                        <select class="input" id="priority" name="priority">
                            <?php foreach (TASK_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $v('priority', 'medium') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="status">Status</label>
                        <select class="input" id="status" name="status">
                            <?php foreach (TASK_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $v('status', 'todo') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="label" for="due_date">Due date</label>
                    <input class="input" type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>">
                    <?= field_error($errors, 'due_date') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div><h2 class="card-title">Related to</h2><p class="text-xs text-slate-500">Optional.</p></div></div>
            <div class="card-body space-y-4">
                <div x-data="taskCustomerPicker(<?= e(json_encode($linkedCustomer ? ['id' => (int) $linkedCustomer['id'], 'name' => customer_name($linkedCustomer), 'email' => $linkedCustomer['email']] : null)) ?>)" @click.outside="results = []" class="relative">
                    <label class="label">Customer</label>
                    <input type="hidden" name="customer_id" :value="selected ? selected.id : ''">
                    <template x-if="selected">
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                            <div class="min-w-0"><div class="truncate text-sm font-medium" x-text="selected.name"></div><div class="truncate text-xs text-slate-500" x-text="selected.email"></div></div>
                            <button type="button" class="btn-icon" @click="selected = null" aria-label="Remove customer"><?= icon('x', 'h-4 w-4') ?></button>
                        </div>
                    </template>
                    <template x-if="!selected">
                        <input class="input" x-model="q" @input.debounce.250ms="search()" placeholder="Search by name, email, company…" autocomplete="off">
                    </template>
                    <div x-show="results.length" x-cloak class="dropdown left-0 right-0 !min-w-0 max-h-64 overflow-y-auto overflow-x-hidden">
                        <template x-for="c in results" :key="c.id">
                            <button type="button" class="dropdown-item min-w-0 flex-col !items-start !gap-0" @click="selected = c; results = []; q = ''">
                                <span class="w-full truncate font-medium" x-text="c.name"></span><span class="w-full truncate text-xs text-slate-500" x-text="c.email"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="label" for="campaign_id">Campaign</label>
                    <select class="input" id="campaign_id" name="campaign_id">
                        <option value="">None</option>
                        <?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $v('campaign_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="<?= e(url(!empty($task['id']) ? 'tasks/view.php' : 'tasks/index.php', !empty($task['id']) ? ['id' => $task['id']] : [])) ?>" class="btn-secondary">Cancel</a>
            <button class="btn-primary"><?= !empty($task['id']) ? 'Save task' : 'Create task' ?></button>
        </div>
    </div>
</div>
<script>
window.taskCustomerPicker = (initial) => ({
    selected: initial, q: '', results: [],
    async search() {
        if (this.q.trim().length < 2) { this.results = []; return; }
        const r = await api(appUrl('api/customers.php?limit=10&q=' + encodeURIComponent(this.q.trim())));
        this.results = r.customers || [];
    },
});
</script>
