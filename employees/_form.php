<?php
defined('APP_ROOT') || exit;
/**
 * Shared employee form. Expects: $employee (array, may be empty), $users, $departments, $errors.
 */
$v = fn(string $k, mixed $default = '') => old($k, $employee[$k] ?? $default);
$isNew = empty($employee['id']);
?>
<?= csrf_field() ?>
<div class="grid gap-6 lg:grid-cols-3" x-data="{ status: <?= e(json_encode((string) $v('status', 'working'))) ?> }">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Personal details</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="full_name">Full name <span class="text-red-500">*</span></label>
                    <input class="input" id="full_name" name="full_name" value="<?= e($v('full_name')) ?>" required maxlength="150">
                    <?= field_error($errors, 'full_name') ?>
                </div>
                <div>
                    <label class="label" for="email">Email</label>
                    <input class="input" type="email" id="email" name="email" value="<?= e($v('email')) ?>" maxlength="190">
                    <?= field_error($errors, 'email') ?>
                </div>
                <div>
                    <label class="label" for="phone">Phone</label>
                    <input class="input" id="phone" name="phone" value="<?= e($v('phone')) ?>" maxlength="50">
                    <?= field_error($errors, 'phone') ?>
                </div>
                <div>
                    <label class="label" for="date_of_birth">Date of birth</label>
                    <input class="input" type="date" id="date_of_birth" name="date_of_birth" value="<?= e($v('date_of_birth')) ?>">
                    <?= field_error($errors, 'date_of_birth') ?>
                </div>
                <div>
                    <label class="label" for="national_id">National ID / CNIC / Passport no.</label>
                    <input class="input" id="national_id" name="national_id" value="<?= e($v('national_id')) ?>" maxlength="50">
                    <?= field_error($errors, 'national_id') ?>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="address">Address</label>
                    <textarea class="input" id="address" name="address" rows="2" maxlength="1000"><?= e($v('address')) ?></textarea>
                    <?= field_error($errors, 'address') ?>
                </div>
                <div>
                    <label class="label" for="emergency_contact_name">Emergency contact</label>
                    <input class="input" id="emergency_contact_name" name="emergency_contact_name" value="<?= e($v('emergency_contact_name')) ?>" maxlength="150" placeholder="Name and relation">
                    <?= field_error($errors, 'emergency_contact_name') ?>
                </div>
                <div>
                    <label class="label" for="emergency_contact_phone">Emergency phone</label>
                    <input class="input" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= e($v('emergency_contact_phone')) ?>" maxlength="50">
                    <?= field_error($errors, 'emergency_contact_phone') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Pay</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="label" for="salary">Salary (monthly)</label>
                    <input class="input" type="number" step="0.01" min="0" id="salary" name="salary" value="<?= e($v('salary')) ?>">
                    <?= field_error($errors, 'salary') ?>
                </div>
                <div>
                    <label class="label" for="salary_currency">Currency</label>
                    <input class="input uppercase" id="salary_currency" name="salary_currency" value="<?= e($v('salary_currency')) ?>" maxlength="3" placeholder="USD">
                    <?= field_error($errors, 'salary_currency') ?>
                </div>
                <div class="sm:col-span-3">
                    <label class="label" for="bank_details">Bank details</label>
                    <input class="input" id="bank_details" name="bank_details" value="<?= e($v('bank_details')) ?>" maxlength="500" placeholder="Bank, account title, IBAN / account no.">
                    <?= field_error($errors, 'bank_details') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Notes</h2></div>
            <div class="card-body">
                <textarea class="input" id="notes" name="notes" rows="4" maxlength="20000" placeholder="Anything else worth remembering about this employee."><?= e($v('notes')) ?></textarea>
                <?= field_error($errors, 'notes') ?>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Employment</h2></div>
            <div class="card-body space-y-4">
                <div>
                    <label class="label" for="status">Status</label>
                    <select class="input" id="status" name="status" x-model="status">
                        <?php foreach (EMPLOYEE_STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="employee_code">Employee ID</label>
                    <input class="input" id="employee_code" name="employee_code" value="<?= e($v('employee_code')) ?>" maxlength="50" placeholder="EMP-001">
                    <?= field_error($errors, 'employee_code') ?>
                </div>
                <div>
                    <label class="label" for="job_title">Job title</label>
                    <input class="input" id="job_title" name="job_title" value="<?= e($v('job_title')) ?>" maxlength="150" placeholder="Sales closer">
                    <?= field_error($errors, 'job_title') ?>
                </div>
                <div>
                    <label class="label" for="department">Department</label>
                    <input class="input" id="department" name="department" value="<?= e($v('department')) ?>" maxlength="100" list="department-list" placeholder="Sales">
                    <datalist id="department-list"><?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
                    <?= field_error($errors, 'department') ?>
                </div>
                <div>
                    <label class="label" for="employment_type">Employment type</label>
                    <select class="input" id="employment_type" name="employment_type">
                        <?php foreach (EMPLOYEE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $v('employment_type', 'full_time') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="join_date">Joining date</label>
                    <input class="input" type="date" id="join_date" name="join_date" value="<?= e($v('join_date')) ?>">
                    <?= field_error($errors, 'join_date') ?>
                </div>
                <div x-show="['notice_period', 'left', 'terminated'].includes(status)" x-cloak class="space-y-4">
                    <div>
                        <label class="label" for="leave_date"><span x-text="status === 'notice_period' ? 'Last working day' : 'Leaving date'"></span></label>
                        <input class="input" type="date" id="leave_date" name="leave_date" value="<?= e($v('leave_date')) ?>">
                        <?= field_error($errors, 'leave_date') ?>
                    </div>
                    <div>
                        <label class="label" for="leave_reason">Reason for leaving</label>
                        <textarea class="input" id="leave_reason" name="leave_reason" rows="2" maxlength="500"><?= e($v('leave_reason')) ?></textarea>
                        <?= field_error($errors, 'leave_reason') ?>
                    </div>
                </div>
                <div>
                    <label class="label" for="user_id">CRM account</label>
                    <select class="input" id="user_id" name="user_id">
                        <option value="">Not linked</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $v('user_id') === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option><?php endforeach; ?>
                    </select>
                    <p class="help">Link this employee to their login in this workspace, if they have one.</p>
                    <?= field_error($errors, 'user_id') ?>
                </div>
            </div>
        </div>

        <?php if ($isNew): ?>
        <div class="card">
            <div class="card-header"><div><h2 class="card-title">Contract</h2><p class="text-xs text-slate-500">Optional. More documents can be added after saving.</p></div></div>
            <div class="card-body">
                <input class="input" type="file" name="contract_file" accept="<?= e('.' . implode(',.', array_keys(ALLOWED_ATTACHMENT_TYPES))) ?>">
                <p class="help">Up to <?= UPLOAD_LIMIT_MB ?> MB. PDF, Word, images…</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex justify-end gap-2">
            <a href="<?= e(url($isNew ? 'employees/index.php' : 'employees/view.php', $isNew ? [] : ['id' => $employee['id']])) ?>" class="btn-secondary">Cancel</a>
            <button class="btn-primary"><?= $isNew ? 'Add employee' : 'Save changes' ?></button>
        </div>
    </div>
</div>
