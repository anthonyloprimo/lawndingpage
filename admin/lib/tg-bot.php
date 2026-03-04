<?php
// Minimal Telegram Bot API client (HTTP over HTTPS).

class TgBotClient {
    private string $token;
    private string $apiBase;

    public function __construct(string $token, string $apiBase = 'https://api.telegram.org') {
        $this->token = $token;
        $this->apiBase = rtrim($apiBase, '/');
    }

    public function request(string $method, array $params = []): array {
        $url = $this->apiBase . '/bot' . $this->token . '/' . $method;
        $payload = json_encode($params);
        if ($payload === false) {
            return ['ok' => false, 'description' => 'JSON encode failed'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'description' => $err ?: 'Request failed'];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid response'];
    }

    public function sendMessage($chatId, string $text): array {
        return $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    public function getChatMember($chatId, $userId): array {
        return $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function setWebhook(string $url, ?string $secretToken = null): array {
        $params = ['url' => $url];
        if ($secretToken) {
            $params['secret_token'] = $secretToken;
        }
        return $this->request('setWebhook', $params);
    }
}
