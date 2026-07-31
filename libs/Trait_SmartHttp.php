<?php

declare(strict_types=1);

/**
 * SmartHttp Trait — Einbinden in Module für zentrale HTTP-Requests (cURL).
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_SmartHttp.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use SmartHttp_Trait;
 *       ...
 *       $data = $this->HttpRequest('http://api.example.com', 'GET', [], [], 5);
 *   }
 */
if (!trait_exists('SmartHttp_Trait')) {
    trait SmartHttp_Trait
    {
        /**
         * Führt einen HTTP Request aus und liefert das dekodierte JSON-Array zurück.
         * Erfordert, dass das Modul auch Trait_SmartLog einbindet (für Fehler-Logging).
         *
         * @param string $url URL für den Request
         * @param string $method HTTP-Methode (GET, POST, PUT, DELETE)
         * @param array $headers Optionale HTTP-Header als ['Key: Value', ...]
         * @param mixed $payload Optionaler Body/Payload (wird bei Array als JSON kodiert)
         * @param int $timeout Timeout in Sekunden
         * @return array|null Gibt das JSON-dekodierte Array zurück oder null bei Fehlern.
         */
        protected function HttpRequest(string $url, string $method = 'GET', array $headers = [], $payload = null, int $timeout = 5, bool $expectJson = true): ?array
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            if ($payload !== null) {
                if (is_array($payload)) {
                    $payload = json_encode($payload);
                    // Sicherstellen, dass Content-Type gesetzt ist, wenn es JSON ist und noch nicht im Header
                    $hasContentType = false;
                    foreach ($headers as $header) {
                        if (stripos($header, 'Content-Type:') === 0) {
                            $hasContentType = true;
                            break;
                        }
                    }
                    if (!$hasContentType) {
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode >= 400) {
                $errorMsg = "HTTP Request Error [$method $url] - Code: $httpCode | Error: $error";
                if (method_exists($this, 'SLogError')) {
                    $this->SLogError($errorMsg, (string)$response);
                } else {
                    $this->SendDebug('HttpRequest', $errorMsg, 0);
                }
                return null;
            }

            if (trim((string)$response) === '') {
                return [];
            }

            if (!$expectJson) {
                return ['response' => $response];
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = "HTTP Response JSON Parse Error [$method $url] - " . json_last_error_msg();
                if (method_exists($this, 'SLogError')) {
                    $this->SLogError($errorMsg, $response);
                } else {
                    $this->SendDebug('HttpRequest', $errorMsg, 0);
                }
                return null;
            }

            return is_array($data) ? $data : [$data];
        }
    }
}
