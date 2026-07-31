<?php

declare(strict_types=1);

/**
 * SmartHttp Trait — Zentrale HTTP-Requests (cURL) mit Retry & Exponential Backoff.
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_SmartHttp.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use SmartHttp_Trait;
 *       ...
 *       $data = $this->HttpRequest('http://api.example.com', 'GET', [], [], 5);
 *   }
 *
 * Retry/Backoff (für Polling-Module):
 *   // In Create(): Timer mit fester Basis-Frequenz registrieren
 *   // In Timer-Callback:
 *   $data = $this->HttpRequestWithRetry('http://api.example.com', 'UpdateTimer', 60);
 *   // Passt Timer-Intervall automatisch an: Erfolg → 60s, Fehler → 1min/2min/4min.../30min
 */
if (!trait_exists('SmartHttp_Trait')) {
    trait SmartHttp_Trait
    {
        /** Maximales Backoff-Intervall in Minuten */
        private const SH_MAX_BACKOFF_MINUTES = 30;

        // -------------------------------------------------------------------
        // Basis HTTP-Request
        // -------------------------------------------------------------------

        /**
         * Führt einen HTTP Request aus und liefert das dekodierte JSON-Array zurück.
         * Erfordert, dass das Modul auch Trait_SmartLog einbindet (für Fehler-Logging).
         *
         * @param string $url        URL für den Request
         * @param string $method     HTTP-Methode (GET, POST, PUT, DELETE)
         * @param array  $headers    Optionale HTTP-Header als ['Key: Value', ...]
         * @param mixed  $payload    Optionaler Body/Payload (wird bei Array als JSON kodiert)
         * @param int    $timeout    Timeout in Sekunden
         * @param bool   $expectJson false = rohe Response zurückgeben statt JSON zu dekodieren
         * @return array|null Gibt das JSON-dekodierte Array zurück oder null bei Fehlern.
         */
        protected function HttpRequest(string $url, string $method = 'GET', array $headers = [], mixed $payload = null, int $timeout = 5, bool $expectJson = true): ?array
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
                    // Content-Type setzen wenn nicht bereits vorhanden
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
            $error    = curl_error($ch);
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

        // -------------------------------------------------------------------
        // Retry & Exponential Backoff für Polling-Module
        // -------------------------------------------------------------------

        /**
         * Führt einen HTTP-Request aus und passt das Timer-Intervall automatisch an.
         * Bei Erfolg: normales Intervall. Bei Fehler: Exponential Backoff (1→2→4→...→30 Min).
         *
         * Verwendung in Polling-Modulen:
         *   // In Create():
         *   $this->RegisterTimer('UpdateTimer', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Update", "");');
         *
         *   // In Timer-Callback / RequestAction('Update'):
         *   $data = $this->HttpRequestWithRetry(
         *       'https://api.example.com/data',
         *       'UpdateTimer',
         *       60,          // normal interval in seconds
         *       'GET',
         *       ['Authorization: Bearer ' . $token]
         *   );
         *   if ($data !== null) {
         *       // Daten verarbeiten...
         *   }
         *
         * @param string $url                URL für den Request
         * @param string $timerName          Name des Polling-Timers (für Backoff-Anpassung)
         * @param int    $normalIntervalSecs Normales Polling-Intervall in Sekunden bei Erfolg
         * @param string $method             HTTP-Methode (Standard: GET)
         * @param array  $headers            Optionale HTTP-Header
         * @param mixed  $payload            Optionaler Body/Payload
         * @param int    $timeout            Timeout in Sekunden
         * @return array|null Response-Daten bei Erfolg, null bei Fehler
         */
        protected function HttpRequestWithRetry(
            string $url,
            string $timerName,
            int    $normalIntervalSecs,
            string $method  = 'GET',
            array  $headers = [],
            mixed  $payload = null,
            int    $timeout = 10
        ): ?array {
            $result = $this->HttpRequest($url, $method, $headers, $payload, $timeout);

            if ($result !== null) {
                // Erfolg: Fehlerzähler zurücksetzen, normales Intervall wiederherstellen
                $this->SetBuffer('SH_ConsecutiveFailures', '0');
                $this->SetTimerInterval($timerName, $normalIntervalSecs * 1000);
                return $result;
            }

            // Fehler: Exponential Backoff berechnen
            $failures = (int)$this->GetBuffer('SH_ConsecutiveFailures') + 1;
            $this->SetBuffer('SH_ConsecutiveFailures', (string)$failures);

            // 2^(failures-1) Minuten, max 30 Minuten
            $backoffMinutes = min((int)pow(2, $failures - 1), self::SH_MAX_BACKOFF_MINUTES);
            $this->SetTimerInterval($timerName, $backoffMinutes * 60 * 1000);

            $msg = sprintf(
                'HTTP-Anfrage fehlgeschlagen (#%d), nächster Versuch in %d Min: %s',
                $failures,
                $backoffMinutes,
                $url
            );
            if (method_exists($this, 'SLogWarning')) {
                $this->SLogWarning($msg);
            } else {
                $this->SendDebug('HttpRetry', $msg, 0);
            }

            return null;
        }

        /**
         * Setzt den Backoff-Fehlerzähler manuell zurück (z.B. nach erfolgreichem Auth-Refresh).
         */
        protected function SH_ResetRetryCounter(): void
        {
            $this->SetBuffer('SH_ConsecutiveFailures', '0');
        }

        /**
         * Gibt die aktuelle Anzahl aufeinanderfolgender Fehler zurück.
         */
        protected function SH_GetConsecutiveFailures(): int
        {
            return (int)$this->GetBuffer('SH_ConsecutiveFailures');
        }
    }
}
