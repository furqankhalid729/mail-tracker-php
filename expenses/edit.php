<?php
/**
 * Add or edit an expense (?id= to edit), with an optional receipt (PDF or image).
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('expenses.manage');
$ws = ws_id();
$expense = input_int('id') ? find_or_404('expenses', input_int('id')) : null;

if (is_post()) {
    verify_csrf();
    $errors = validate($_POST, ['title' => 'required|max:200', 'vendor' => 'max:150', 'reference' => 'max:100', 'notes' => 'max:1000'], ['title' => 'Description']);
    $date = (string) input('expense_date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        $errors['expense_date'] = 'Choose a date.';
    }
    $amount = amount_from_input(input('amount'));
    if ($amount === null || $amount <= 0) {
        $errors['amount'] = 'Enter an amount.';
    }
    if ($errors) {
        keep_old_input();
        flash_errors($errors);
        redirect(url('expenses/edit.php', ['id' => $expense['id'] ?? null]));
    }
    $data = [
        'expense_date' => $date,
        'title' => trim((string) input('title')),
        'vendor' => trim((string) input('vendor')) ?: null,
        'category' => isset(EXPENSE_CATEGORIES[input('category')]) ? (string) input('category') : 'other',
        'amount' => $amount,
        'currency' => APP_CURRENCY,
        'payment_method' => mb_substr(trim((string) input('payment_method')), 0, 50) ?: null,
        'reference' => trim((string) input('reference')) ?: null,
        'notes' => trim((string) input('notes')) ?: null,
        'updated_at' => now(),
    ];
    [$file, $fileError] = store_private_upload('receipt', "expenses/$ws/" . date('Y/m'), RECEIPT_TYPES);
    if ($file) {
        $data += ['receipt_path' => $file['storage_path'], 'receipt_name' => $file['filename'], 'receipt_mime' => $file['mime_type']];
    }
    if ($expense) {
        db_update('expenses', $data, 'id = ? AND workspace_id = ?', [$expense['id'], $ws]);
        if ($file && $expense['receipt_path']) {
            delete_private_uploads([$expense['receipt_path']], 'expenses/'); // replaced
        }
        flash('success', 'Expense saved.');
    } else {
        db_insert('expenses', $data + ['workspace_id' => $ws, 'created_by' => user_id(), 'created_at' => now()]);
        flash('success', 'Expense added.');
    }
    if ($fileError) {
        flash('error', 'The receipt was not uploaded: ' . $fileError);
    }
    clear_old_input();
    redirect(url('expenses/index.php', ['month' => substr($date, 0, 7)]));
}

$errors = get_errors();
$v = fn(string $k, mixed $default = '') => old($k, $expense[$k] ?? $default);
$vendors = q_col("SELECT vendor FROM expenses WHERE workspace_id = ? AND vendor IS NOT NULL GROUP BY vendor ORDER BY COUNT(*) DESC LIMIT 50", [$ws]);

$page_title = $expense ? 'Edit expense' : 'Add expense';
$active_nav = 'expenses';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('expenses/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Expenses</a>
        <h1 class="page-title"><?= $expense ? 'Edit expense' : 'Add expense' ?></h1>
    </div>
</div>
<form method="post" enctype="multipart/form-data" class="card max-w-3xl">
    <?= csrf_field() ?>
    <div class="card-body grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="label" for="title">Description <span class="text-red-500">*</span></label>
            <input class="input" id="title" name="title" value="<?= e($v('title')) ?>" required maxlength="200" placeholder="Facebook ads, new laptop, office rent…">
            <?= field_error($errors, 'title') ?>
        </div>
        <div>
            <label class="label" for="amount">Amount (<?= e(APP_CURRENCY) ?>) <span class="text-red-500">*</span></label>
            <input class="input" id="amount" name="amount" value="<?= e($v('amount')) ?>" required inputmode="decimal" placeholder="0.00">
            <p class="help">For something billed in dollars, enter what was charged in <?= e(APP_CURRENCY) ?>.</p>
            <?= field_error($errors, 'amount') ?>
        </div>
        <div>
            <label class="label" for="expense_date">Date <span class="text-red-500">*</span></label>
            <input class="input" type="date" id="expense_date" name="expense_date" value="<?= e($v('expense_date', date('Y-m-d'))) ?>" required>
            <?= field_error($errors, 'expense_date') ?>
        </div>
        <div>
            <label class="label" for="category">Category</label>
            <select class="input" id="category" name="category">
                <?php foreach (EXPENSE_CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $v('category', 'other') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="label" for="vendor">Vendor / paid to</label>
            <input class="input" id="vendor" name="vendor" value="<?= e($v('vendor')) ?>" maxlength="150" list="vendors" placeholder="Meta, Daraz, landlord…">
            <datalist id="vendors"><?php foreach ($vendors as $vd): ?><option value="<?= e($vd) ?>"><?php endforeach; ?></datalist>
        </div>
        <div>
            <label class="label" for="payment_method">Paid with</label>
            <input class="input" id="payment_method" name="payment_method" value="<?= e($v('payment_method')) ?>" maxlength="50" list="pay-methods" placeholder="Credit card">
            <datalist id="pay-methods"><?php foreach (PAYMENT_METHODS as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
        </div>
        <div>
            <label class="label" for="reference">Invoice / reference no.</label>
            <input class="input" id="reference" name="reference" value="<?= e($v('reference')) ?>" maxlength="100">
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="receipt">Receipt</label>
            <?php if ($expense && $expense['receipt_path']): ?>
                <p class="mb-2 text-sm"><a class="font-medium text-indigo-600 hover:underline" href="<?= e(url('expenses/receipt.php', ['id' => $expense['id']])) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 'mr-1 inline h-4 w-4') ?><?= e($expense['receipt_name']) ?></a> <span class="text-xs text-slate-400">· choose a file to replace it</span></p>
            <?php endif; ?>
            <input class="input" type="file" id="receipt" name="receipt" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif">
            <p class="help">PDF or image, up to <?= UPLOAD_LIMIT_MB ?> MB.</p>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="2" maxlength="1000"><?= e($v('notes')) ?></textarea>
        </div>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
        <a href="<?= e(url('expenses/index.php')) ?>" class="btn-secondary">Cancel</a>
        <button class="btn-primary"><?= $expense ? 'Save expense' : 'Add expense' ?></button>
    </div>
</form>
<?php require __DIR__ . '/../includes/footer.php';
