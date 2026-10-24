<?php
// Fake OpenRouter Chat Completions for LOCAL TESTING ONLY. Never deploy settings that point at it.
// Run from the project root:  php -S 127.0.0.1:8098 scripts/mock-openrouter/router.php
// .env: OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1   (OPENROUTER_API_KEY may stay empty)
// The model name picks the behaviour: mock/fenced, mock/prose, mock/401, mock/402, mock/no-json-mode; anything else → valid JSON.
// The last request body is saved to scripts/mock-openrouter/last-request.json.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = function ($data, $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };
if ($path !== '/api/v1/chat/completions' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $json(['error' => ['code' => 404, 'message' => 'Not found']], 404);
}
$raw = file_get_contents('php://input');
file_put_contents(__DIR__ . '/last-request.json', $raw);
$req = json_decode($raw, true) ?: [];
$model = (string) ($req['model'] ?? '');
$mode = str_starts_with($model, 'mock/') ? substr($model, 5) : 'ok';

if ($mode === '401') {
    $json(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 401);
}
if ($mode === '402') {
    $json(['error' => ['code' => 402, 'message' => 'Insufficient credits']], 402);
}
if ($mode === 'no-json-mode' && isset($req['response_format'])) {
    $json(['error' => ['code' => 400, 'message' => 'This model does not support response_format json_object']], 400);
}

// Echo the company from the user message so tests can see which customer was drafted
preg_match('/^company: (.*)$/m', (string) ($req['messages'][1]['content'] ?? ''), $m);
$company = trim($m[1] ?? 'your business');
$reply = json_encode([
    'research' => "Niche: plumbing. Location: Austin, TX. Competitors: Acme Plumbing (24/7 service), Rapid Rooter (online booking). Sources: website, web search.",
    'subject' => "Quick idea for $company",
    'html_body' => "<p>Hi there,</p><p>I looked at $company and noticed competitors offer online booking.</p><p>Best,<br>Sam</p>",
    'text_body' => "Hi there,\n\nI looked at $company and noticed competitors offer online booking.\n\nBest,\nSam",
]);
$content = match ($mode) {
    'fenced' => "Here is the email:\n```json\n$reply\n```",
    'prose' => 'Sorry, I could not complete this request.',
    default => $reply,
};
$json([
    'id' => 'gen-mock',
    'model' => $model,
    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'total_tokens' => 1500, 'cost' => 0.0021, 'server_tool_use' => ['web_search_requests' => 2]],
]);
