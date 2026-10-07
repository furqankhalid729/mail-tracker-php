<?php
defined('APP_ROOT') || exit;
/** Shared template form. Expects $template (array), $errors, $previewCustomers. */
$v = fn(string $k) => old($k, $template[$k] ?? '');
$sample = ['firstName' => 'Jane', 'lastName' => 'Doe', 'fullName' => 'Jane Doe', 'company' => 'Acme Inc.', 'email' => 'jane@acme.com', 'website' => 'https://acme.com', 'jobTitle' => 'Head of Growth', 'country' => 'USA'];
$previewVars = ['sample' => $sample];
foreach ($previewCustomers as $pc) {
    $previewVars[(string) $pc['id']] = template_vars($pc);
}
?>
<?= csrf_field() ?>
<div class="grid gap-6 xl:grid-cols-2" x-data="templateEditor()" x-init="$nextTick(() => update())">
    <div class="space-y-4">
        <div class="card card-body space-y-4">
            <div>
                <label class="label" for="name">Template name</label>
                <input class="input" id="name" name="name" value="<?= e($v('name')) ?>" required maxlength="150" placeholder="Shopify intro">
                <?= field_error($errors, 'name') ?>
            </div>
            <div>
                <label class="label" for="subject">Subject</label>
                <input class="input" id="subject" name="subject" x-model="subject" @input="update()" required maxlength="255" placeholder="Quick question about {{company}}">
                <?= field_error($errors, 'subject') ?>
            </div>
            <div>
                <div class="flex items-end justify-between">
                    <label class="label" for="html_body">HTML body</label>
                    <div class="mb-1.5 flex flex-wrap justify-end gap-1">
                        <?php foreach (TEMPLATE_VARIABLES as $var): ?>
                            <button type="button" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 hover:bg-indigo-100" @click="insert('<?= e($var) ?>')">{{<?= e($var) ?>}}</button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <textarea class="input font-mono text-xs" id="html_body" name="html_body" rows="16" x-ref="body" x-model="html" @input.debounce.200ms="update()" spellcheck="false" placeholder="<p>Hi {{firstName|there}},</p>"></textarea>
                <p class="help">Use <code>{{variable}}</code> or <code>{{variable|fallback}}</code>. Custom fields work too, e.g. <code>{{industry}}</code>. Scripts and forms are stripped on save.</p>
                <?= field_error($errors, 'html_body') ?>
            </div>
            <div>
                <label class="label" for="text_body">Plain-text version <span class="font-normal text-slate-400">(optional — generated from HTML if empty)</span></label>
                <textarea class="input font-mono text-xs" id="text_body" name="text_body" rows="5"><?= e($v('text_body')) ?></textarea>
            </div>
        </div>
        <div class="flex justify-end gap-2">
            <a href="<?= e(url('templates/index.php')) ?>" class="btn-secondary">Cancel</a>
            <button class="btn-primary">Save template</button>
        </div>
    </div>

    <div class="card sticky top-20 flex h-fit flex-col overflow-hidden">
        <div class="card-header">
            <h2 class="card-title">Live preview</h2>
            <select class="input !w-auto !py-1 text-xs" x-model="who" @change="update()">
                <option value="sample">Sample data</option>
                <?php foreach ($previewCustomers as $pc): ?><option value="<?= (int) $pc['id'] ?>"><?= e(customer_name($pc)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="border-b border-slate-100 px-5 py-3 text-sm"><span class="text-slate-500">Subject:</span> <span class="font-medium" x-text="previewSubject"></span></div>
        <iframe x-ref="frame" sandbox="" class="h-[32rem] w-full bg-white" title="Template preview"></iframe>
    </div>
</div>
<script>
function templateEditor() {
    return {
        subject: <?= json_encode((string) $v('subject'), JSON_HEX_TAG) ?>,
        html: <?= json_encode((string) $v('html_body'), JSON_HEX_TAG) ?>,
        who: 'sample', previewSubject: '',
        vars: <?= json_encode($previewVars, JSON_HEX_TAG) ?>,
        update() {
            const vars = this.vars[this.who] || this.vars.sample;
            this.previewSubject = renderVars(this.subject, vars) || '(no subject)';
            // Sandboxed iframe without allow-scripts: nothing in the template can execute
            this.$refs.frame.srcdoc = '<style>body{font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#1f2937;padding:16px;margin:0}a{color:#4f46e5}</style>' + renderVars(this.html, vars, true);
        },
        insert(v) {
            const el = this.$refs.body, s = el.selectionStart ?? this.html.length;
            this.html = this.html.slice(0, s) + '{{' + v + '}}' + this.html.slice(el.selectionEnd ?? s);
            this.$nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = s + v.length + 4; this.update(); });
        },
    };
}
</script>
