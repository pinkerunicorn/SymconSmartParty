<?php

declare(strict_types=1);

/**
 * DeviceAvailability Trait — Standardisiertes Online/Offline-Tracking für alle Module mit externer Verbindung.
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use SmartLog_Trait;
 *       use NotificationAware_Trait; // optional, für Alarm-Push
 *       use DeviceAvailability_Trait;
 *   }
 *
 * In Create():
 *   $this->DA_RegisterAvailability();           // immer
 *   $this->DA_RegisterWatchdog();               // nur bei Push-basierten Modulen (MQTT, SSE, WS)
 *
 * Bei eingehenden Daten:
 *   $this->DA_SetAvailable(true);
 *   $this->DA_ResetWatchdog(300);               // nur bei Watchdog-Modulen
 *
 * Bei Fehlern (Catch-Blöcke):
 *   $this->DA_SetAvailable(false, 'Reason');
 *
 * In RequestAction():
 *   case 'DA_Watchdog': $this->DA_HandleWatchdog(); break;
 */
if (!trait_exists('DeviceAvailability_Trait')) {
    trait DeviceAvailability_Trait
    {
        // -------------------------------------------------------------------
        // Registrierung
        // -------------------------------------------------------------------

        /**
         * Registriert die DeviceAvailable-Variable.
         * Aufruf in Create() des Moduls.
         *
         * @param int $position Position im Webfront (Standard: 900 = Diagnostik-Range)
         */
        private function DA_RegisterAvailability(int $position = 900): void
        {
            $options = json_encode([
                [
                    'Value'               => false,
                    'Caption'             => 'Offline',
                    'IconValue'           => 'NetworkDisconnected',
                    'IconActive'          => true,
                    'ColorActive'         => true,
                    'ColorDisplay'        => 0xFF4444,
                    'ContentColorActive'  => false,
                    'ContentColorDisplay' => -1,
                    'ContentColorValue'   => -1,
                    'ColorValue'          => 0xFF4444
                ],
                [
                    'Value'               => true,
                    'Caption'             => 'Online',
                    'IconValue'           => 'network-wired',
                    'IconActive'          => true,
                    'ColorActive'         => true,
                    'ColorDisplay'        => 0x00CC44,
                    'ContentColorActive'  => false,
                    'ContentColorDisplay' => -1,
                    'ContentColorValue'   => -1,
                    'ColorValue'          => 0x00CC44
                ]
            ]);

            $this->RegisterVariableBoolean('DeviceAvailable', 'Gerätestatus', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'network-wired',
                'OPTIONS'      => $options
            ], $position);

            // Property für Alarm-Priorität (0=Low, 1=Medium, 2=High, -1=kein Alarm)
            // RegisterPropertyInteger ist idempotent in Symcon – kein Existenz-Check nötig
            $this->RegisterPropertyInteger('AvailabilityAlarmPriority', 1);

            // Set initial state to true (Online) on creation so newly created variables don't falsely trigger offline alarms
            if ($this->GetValue('DeviceAvailable') === false && time() - IPS_GetVariable($this->GetIDForIdent('DeviceAvailable'))['VariableUpdated'] > 31536000) {
                $this->SetValue('DeviceAvailable', true);
            }
        }

        /**
         * Registriert den Watchdog-Timer für Push-basierte Module (MQTT, SSE, WebSocket).
         * Aufruf in Create() des Moduls.
         */
        private function DA_RegisterWatchdog(): void
        {
            $this->RegisterTimer('DA_Watchdog', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "DA_Watchdog", "");');
        }

        // -------------------------------------------------------------------
        // Status setzen
        // -------------------------------------------------------------------

        /**
         * Setzt den Gerätestatus und löst bei Offline-Wechsel optional einen Alarm aus.
         *
         * @param bool   $available true = online, false = offline
         * @param string $reason    Optionaler Grund für den Offline-Status
         */
        private function DA_SetAvailable(bool $available, string $reason = ''): void
        {
            $wasAvailable = (bool)@$this->GetValue('DeviceAvailable');

            // Nur reagieren wenn sich der Status ändert
            if ($wasAvailable === $available) {
                return;
            }

            $this->SetValue('DeviceAvailable', $available);

            $instanceName = IPS_GetName($this->InstanceID);

            if ($available) {
                // Gerät wieder online
                if (method_exists($this, 'SLogInfo')) {
                    $this->SLogInfo('Gerät wieder erreichbar', $instanceName);
                }
            } else {
                // Gerät offline gegangen
                $message = 'Gerät nicht erreichbar' . ($reason !== '' ? ': ' . $reason : '');
                if (method_exists($this, 'SLogWarning')) {
                    $this->SLogWarning($message, $instanceName);
                }

                // Alarm senden wenn Priorität konfiguriert und NotificationAware_Trait verfügbar
                $priority = (int)$this->ReadPropertyInteger('AvailabilityAlarmPriority');
                if ($priority >= 0 && method_exists($this, 'NotifyLow')) {
                    $alarmTitle = '⚠️ Gerät offline';
                    $alarmMsg = $instanceName . ': ' . $message;
                    match($priority) {
                        0 => $this->NotifyLow($alarmTitle, $alarmMsg),
                        1 => $this->NotifyMedium($alarmTitle, $alarmMsg),
                        2 => $this->NotifyHigh($alarmTitle, $alarmMsg),
                        default => $this->NotifyMedium($alarmTitle, $alarmMsg),
                    };
                }
            }
        }

        /**
         * Gibt zurück ob das Gerät aktuell als verfügbar gilt.
         */
        private function DA_IsAvailable(): bool
        {
            return (bool)@$this->GetValue('DeviceAvailable');
        }

        // -------------------------------------------------------------------
        // Watchdog (für Push-basierte Module: MQTT, SSE, WebSocket)
        // -------------------------------------------------------------------

        /**
         * Setzt den Watchdog-Timer zurück (Daten empfangen → online).
         *
         * @param int $timeoutSeconds Timeout in Sekunden (z.B. 300 für 5 Min)
         */
        private function DA_ResetWatchdog(int $timeoutSeconds): void
        {
            $this->SetTimerInterval('DA_Watchdog', $timeoutSeconds * 1000);
            $this->DA_SetAvailable(true);
        }

        /**
         * Deaktiviert den Watchdog-Timer (z.B. beim Modul-Stop oder bei Deaktivierung).
         */
        private function DA_StopWatchdog(): void
        {
            $this->SetTimerInterval('DA_Watchdog', 0);
        }

        /**
         * Watchdog-Timer abgelaufen → Gerät offline markieren.
         * MUSS in RequestAction() des Moduls weitergeleitet werden:
         *   case 'DA_Watchdog': $this->DA_HandleWatchdog(); break;
         */
        private function DA_HandleWatchdog(): void
        {
            $this->SetTimerInterval('DA_Watchdog', 0);
            $this->DA_SetAvailable(false, 'Kein Signal empfangen (Watchdog Timeout)');
        }

        // -------------------------------------------------------------------
        // CustomPresentation
        // -------------------------------------------------------------------

        /**
         * Veraltet: In Symcon 8 muss das Profil direkt bei der Erstellung übergeben werden.
         * Die Logik wurde in DA_RegisterAvailability verschoben.
         * Dummy-Methode zur Abwärtskompatibilität, damit alte module.php nicht sofort brechen.
         */
        private function DA_ApplyPresentation(): void
        {
            // Do nothing
        }
    }
}
