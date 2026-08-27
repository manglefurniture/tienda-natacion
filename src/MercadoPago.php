<?php
declare(strict_types=1);

final class MercadoPago
{
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct(?string $accessToken = null)
    {
        $this->accessToken = trim((string) ($accessToken ?? env('MERCADOPAGO_ACCESS_TOKEN')));
        if ($this->accessToken === '') {
            throw new RuntimeException('Mercado Pago no está configurado.');
        }
    }

    public function createPreference(array $payload): array
    {
        return $this->request('POST', '/checkout/preferences', $payload);
    }

    public function getPayment(string $paymentId): array
    {
        if (!preg_match('/^[0-9]+$/', $paymentId)) {
            throw new InvalidArgumentException('ID de pago no válido.');
        }

        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $body = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('No se pudo iniciar la conexión con Mercado Pago.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('Mercado Pago no respondió: ' . $error);
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $body ?? '',
                    'ignore_errors' => true,
                    'timeout' => 20,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                throw new RuntimeException('No se pudo conectar con Mercado Pago.');
            }
            $httpCode = 0;
            foreach ($http_response_header ?? [] as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                    $httpCode = (int) $matches[1];
                }
            }
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Mercado Pago devolvió una respuesta inválida.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'Error al procesar la solicitud.');
            throw new RuntimeException('Mercado Pago: ' . $message);
        }

        return $decoded;
    }
}
