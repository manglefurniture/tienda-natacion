<?php
declare(strict_types=1);

final class ResendMailer
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public static function sendText(string $to, string $from, string $fromName, string $subject, string $text): string
    {
        $apiKey = trim((string) env('RESEND_API_KEY'));
        if ($apiKey === '') {
            throw new RuntimeException('Resend no está configurado.');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Destinatario de correo inválido.');
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Remitente de correo inválido.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL es necesaria para enviar correos.');
        }

        $payload = json_encode([
            'from' => trim($fromName) . ' <' . $from . '>',
            'to' => [$to],
            'subject' => $subject,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar la conexión con Resend.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Resend no respondió: ' . $curlError);
        }

        $decoded = json_decode((string) $response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['name'] ?? 'Error desconocido')
                : 'Respuesta HTTP ' . $httpCode;
            throw new RuntimeException('Resend: ' . $message);
        }

        $id = is_array($decoded) ? trim((string) ($decoded['id'] ?? '')) : '';
        if ($id === '') {
            throw new RuntimeException('Resend no devolvió un identificador de correo.');
        }

        return $id;
    }
}
