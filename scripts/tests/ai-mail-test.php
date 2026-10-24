<?php
// php scripts/tests/ai-mail-test.php — OpenRouter payload/parse (pure) + ai_draft_email against the mock.
// Mock: php -S 127.0.0.1:8098 scripts/mock-openrouter/router.php   (.env OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1)
require __DIR__ . '/_assert.php';

$c = ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => 'Lee Plumbing', 'website' => 'https://leeplumbing.example', 'email' => 'ann@x.test', 'country' => 'US'];
$o = ['model' => 'm/x', 'research_prompt' => 'Find competitors of {{company}}', 'subject' => 'Idea for {{company}}',
      'keep_subject' => false, 'email_template' => 'Hi {{firstName}}, ...', 'web_search' => true];

t('payload has both tools', function () use ($c, $o) {
    $p = ai_build_payload($c, $o);
    eq($p['model'], 'm/x');
    eq(array_column($p['tools'], 'type'), ['openrouter:web_fetch', 'openrouter:web_search']);
    eq($p['tools'][0]['parameters'], ['engine' => 'openrouter', 'max_uses' => 3, 'max_content_tokens' => 8000]);
    eq($p['tools'][1]['parameters'], ['engine' => 'parallel', 'max_results' => 5, 'max_total_results' => 10]);
    eq($p['max_tool_calls'], 6);
    eq($p['response_format'], ['type' => 'json_object']);
    eq($p['usage'], ['include' => true]);
    eq($p['messages'][0]['role'], 'system');
    ok(str_contains($p['messages'][1]['content'], 'Find competitors of Lee Plumbing'), 'prompt rendered');
    ok(str_contains($p['messages'][1]['content'], 'https://leeplumbing.example'), 'website included');
});
t('web search off drops tool', fn() => eq(array_column(ai_build_payload($c, ['web_search' => false] + $o)['tools'], 'type'), ['openrouter:web_fetch']));
t('json_mode off drops response_format', fn() => ok(!isset(ai_build_payload($c, ['json_mode' => false] + $o)['response_format'])));
t('parse bare json', fn() => eq(ai_parse_reply('{"research":"r","subject":"s","html_body":"<p>h</p>","text_body":"t"}')['subject'], 's'));
t('parse fenced json', fn() => eq(ai_parse_reply("Here:\n```json\n{\"research\":\"r\",\"subject\":\"s\",\"html_body\":\"<p>h</p>\",\"text_body\":\"t\"}\n```")['text_body'], 't'));
t('parse prose throws', function () {
    try { ai_parse_reply('Sorry, I cannot.'); ok(false, 'no exception'); } catch (RuntimeException $e) { eq($e->getMessage(), 'AI reply was not valid JSON'); }
});
t('missing body throws', function () {
    try { ai_parse_reply('{"subject":"s"}'); ok(false, 'no exception'); } catch (RuntimeException $e) { eq($e->getMessage(), 'AI reply missing html_body/text_body'); }
});
t('html sanitised', fn() => ok(!str_contains(ai_parse_reply('{"research":"","subject":"s","html_body":"<p>x</p><script>1</script>","text_body":"t"}')['html_body'], '<script')));
t('empty text_body derived from html', fn() => ok(str_contains(ai_parse_reply('{"research":"","subject":"s","html_body":"<p>Hello there</p>","text_body":""}')['text_body'], 'Hello there')));

$mockUp = (bool) @fsockopen('127.0.0.1', 8098, $errno, $errstr, 0.5);
if (!$mockUp || !str_starts_with(OPENROUTER_API_BASE, 'http://127.0.0.1:8098')) {
    echo "SKIP mock tests (start the mock and set OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1)\n";
    t_done();
}
t('draft via mock', function () use ($c, $o) {
    $r = ai_draft_email($c, $o);
    ok($r['subject'] !== '' && $r['html_body'] !== '' && $r['research'] !== '');
    ok(is_float($r['usage']['cost']));
    eq($r['usage']['web_requests'], 2);
    eq($r['usage']['prompt_tokens'], 1200);
});
t('keep_subject uses rendered subject', fn() => eq(ai_draft_email($c, ['keep_subject' => true] + $o)['subject'], 'Idea for Lee Plumbing'));
t('fenced reply via mock', fn() => ok(ai_draft_email($c, ['model' => 'mock/fenced'] + $o)['html_body'] !== ''));
t('no-json-mode retries without response_format', fn() => ok(ai_draft_email($c, ['model' => 'mock/no-json-mode'] + $o)['html_body'] !== ''));
t('401 error message has status, not key', function () use ($c, $o) {
    try {
        ai_draft_email($c, ['model' => 'mock/401'] + $o);
        ok(false, 'no exception');
    } catch (OpenRouterException $e) {
        eq($e->status, 401);
        ok(str_starts_with($e->getMessage(), 'OpenRouter 401'), $e->getMessage());
        ok(OPENROUTER_API_KEY === '' || !str_contains($e->getMessage(), OPENROUTER_API_KEY));
    }
});
t('prose reply throws parse error', function () use ($c, $o) {
    try { ai_draft_email($c, ['model' => 'mock/prose'] + $o); ok(false, 'no exception'); } catch (RuntimeException $e) { eq($e->getMessage(), 'AI reply was not valid JSON'); }
});
t_done();
