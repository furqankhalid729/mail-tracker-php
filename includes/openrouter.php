<?php
declare(strict_types=1);

/**
 * Minimal OpenRouter Chat Completions client (https://openrouter.ai/docs).
 * Server tools (openrouter:web_fetch, openrouter:web_search) run on OpenRouter's side inside one request,
 * so there is no client-side tool loop here.
 */

final class OpenRouterException extends RuntimeException
{
    public function __construct(string $message, public int $status = 0)
    {
        parent::__construct($message);
    }
}

/**
 * POST a chat completion and return the decoded response.
 * Throws OpenRouterException on network errors, non-2xx responses and error bodies.
 * The message never contains the API key.
 */
function openrouter_chat(array $payload, int $timeout = 120): array
{
    if (OPENROUTER_API_KEY === '' && !str_starts_with(OPENROUTER_API_BASE, 'http://127.0.0.1')) {
        throw new OpenRouterException('OpenRouter API key is not set (OPENROUTER_API_KEY in .env)');
    }
    $ch = curl_init(OPENROUTER_API_BASE . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: ' . APP_URL,
            'X-Title: ' . APP_NAME,
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new OpenRouterException('OpenRouter request failed: ' . $curlError);
    }
    $data = json_decode((string) $body, true);
    $error = is_array($data) ? ($data['error'] ?? null) : null;
    if ($status < 200 || $status >= 300 || $error) {
        $code = $status >= 300 ? $status : (int) (is_array($error) ? ($error['code'] ?? 0) : 0);
        $msg = is_array($error) ? (string) ($error['message'] ?? 'Unknown error') : mb_substr(trim(strip_tags((string) $body)), 0, 200);
        throw new OpenRouterException('OpenRouter ' . $code . ': ' . ($msg !== '' ? $msg : 'HTTP error'), $code);
    }
    if (!is_array($data)) {
        throw new OpenRouterException('OpenRouter returned an unreadable response', $status);
    }
    return $data;
}
