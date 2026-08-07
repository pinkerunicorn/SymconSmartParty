<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartLedFX extends IPSModuleStrict
{
    use SmartLog_Trait;
    use SmartHttp_Trait;
    use DeviceAvailability_Trait;

    // Show-Modi
    private const MODE_WOHNZIMMER   = 0;
    private const MODE_GARTEN       = 1;
    private const MODE_BEIDES       = 2;
    private const MODE_FREQ_SPLIT   = 3;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // LedFx Verbindung
        $this->RegisterPropertyString('LedFxHost', '');
        $this->RegisterPropertyInteger('LedFxPort', 8888);

        // LedFx Szenen pro Modus
        $this->RegisterPropertyString('SceneWohnzimmer', '');
        $this->RegisterPropertyString('SceneGarten', '');
        $this->RegisterPropertyString('SceneBeides', '');
        $this->RegisterPropertyString('SceneFreqSplit', '');
        $this->RegisterPropertyString('SceneOff', '');

        // WLED Controller IPs
        $this->RegisterPropertyString('WledIpWohnzimmer', '');
        $this->RegisterPropertyString('WledIpGarten', '');

        // Health-Check Intervall
        $this->RegisterPropertyInteger('PollInterval', 30);

        // --- Variablen ---

        // Hauptschalter
        $this->RegisterVariableBoolean('ShowActive', 'Audio-Show', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Melody'
        ], 1);

        // Show-Modus (Dropdown)
        $this->RegisterVariableInteger('ShowMode', 'Show-Modus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode([
                ['Value' => self::MODE_WOHNZIMMER, 'Caption' => 'Nur Wohnzimmer', 'IconActive' => true, 'IconValue' => 'Sofa',      'Color' => 0x3399FF],
                ['Value' => self::MODE_GARTEN,     'Caption' => 'Nur Garten',     'IconActive' => true, 'IconValue' => 'Tree',      'Color' => 0x33CC33],
                ['Value' => self::MODE_BEIDES,     'Caption' => 'Beides',         'IconActive' => true, 'IconValue' => 'Light',     'Color' => 0xFF9900],
                ['Value' => self::MODE_FREQ_SPLIT, 'Caption' => 'Frequency Split','IconActive' => true, 'IconValue' => 'Frequency', 'Color' => 0xFF00FF]
            ])
        ], 2);

        // Aktive Szene (read-only)
        $this->RegisterVariableString('ActiveScene', 'Aktive Szene', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Scene'
        ], 10);

        // LedFx Version (read-only)
        $this->RegisterVariableString('LedFxVersion', 'LedFx Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ], 100);

        // Timer
        $this->RegisterTimer('HealthCheckTimer', 0, 'SLFX_HealthCheck($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->DA_ApplyPresentation();

        // Aktionen aktivieren
        $this->EnableAction('ShowActive');
        $this->EnableAction('ShowMode');

        // Validierung
        $host = trim($this->ReadPropertyString('LedFxHost'));
        if (empty($host)) {
            $this->SetStatus(104);
            $this->DA_SetAvailable(false, 'Kein LedFx Host konfiguriert');
            $this->SetTimerInterval('HealthCheckTimer', 0);
            return;
        }

        $this->SetStatus(102);

        // ShowMode ausgrauen wenn Show aktiv
        if ($this->GetValue('ShowActive')) {
            IPS_SetDisabled($this->GetIDForIdent('ShowMode'), true);
        } else {
            IPS_SetDisabled($this->GetIDForIdent('ShowMode'), false);
        }

        // Health-Check Timer starten
        $interval = $this->ReadPropertyInteger('PollInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('HealthCheckTimer', $interval * 1000);
        } else {
            $this->SetTimerInterval('HealthCheckTimer', 0);
        }

        // Initiale Prüfung
        $this->HealthCheck();
    }

    // =========================================================================
    // Actions
    // =========================================================================

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ShowActive':
                if ($Value) {
                    $this->StartShow();
                } else {
                    $this->StopShow();
                }
                break;

            case 'ShowMode':
                // Modus-Wechsel nur erlaubt wenn Show NICHT aktiv
                if ($this->GetValue('ShowActive')) {
                    $this->SLogWarning('Modus-Wechsel waehrend aktiver Show blockiert');
                    return;
                }
                $this->SetValue('ShowMode', $Value);
                break;

            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                break;
        }
    }

    // =========================================================================
    // Show-Steuerung
    // =========================================================================

    /**
     * Startet die Audio-Show im aktuell gewählten Modus.
     */
    private function StartShow(): void
    {
        $mode = $this->GetValue('ShowMode');

        // 1. WLED Live Override aktivieren (nur für betroffene Zonen)
        switch ($mode) {
            case self::MODE_WOHNZIMMER:
                $this->SetWledOverride('WledIpWohnzimmer', true);
                $sceneProperty = 'SceneWohnzimmer';
                break;

            case self::MODE_GARTEN:
                $this->SetWledOverride('WledIpGarten', true);
                $sceneProperty = 'SceneGarten';
                break;

            case self::MODE_BEIDES:
                $this->SetWledOverride('WledIpWohnzimmer', true);
                $this->SetWledOverride('WledIpGarten', true);
                $sceneProperty = 'SceneBeides';
                break;

            case self::MODE_FREQ_SPLIT:
                $this->SetWledOverride('WledIpWohnzimmer', true);
                $this->SetWledOverride('WledIpGarten', true);
                $sceneProperty = 'SceneFreqSplit';
                break;

            default:
                $this->SLogError('Unbekannter Show-Modus: ' . $mode);
                return;
        }

        // 2. LedFx Szene aktivieren
        $sceneId = $this->ReadPropertyString($sceneProperty);
        if (empty($sceneId)) {
            $this->SLogError('Keine LedFx-Szene fuer Modus ' . $mode . ' konfiguriert');
            // Override wieder zuruecksetzen
            $this->ResetAllOverrides();
            return;
        }

        $success = $this->ActivateLedFxScene($sceneId);
        if (!$success) {
            $this->SLogError('LedFx-Szene konnte nicht aktiviert werden: ' . $sceneId);
            $this->ResetAllOverrides();
            return;
        }

        // 3. Status setzen
        $this->SetValue('ShowActive', true);
        $this->SetValue('ActiveScene', $sceneId);

        // 4. ShowMode ausgrauen
        IPS_SetDisabled($this->GetIDForIdent('ShowMode'), true);

        $modeNames = ['Nur Wohnzimmer', 'Nur Garten', 'Beides', 'Frequency Split'];
        $this->SLogInfo('Audio-Show gestartet: ' . ($modeNames[$mode] ?? 'Unbekannt'));
    }

    /**
     * Beendet die Audio-Show und gibt WLED wieder frei.
     */
    private function StopShow(): void
    {
        // 1. LedFx Off-Szene triggern
        $sceneOff = $this->ReadPropertyString('SceneOff');
        if (!empty($sceneOff)) {
            $this->ActivateLedFxScene($sceneOff);
        } else {
            // Fallback: Alle Effekte auf allen Virtuals löschen
            $this->ClearAllLedFxEffects();
        }

        // 2. Kurz warten, damit letzte Art-Net Pakete raus sind
        IPS_Sleep(500);

        // 3. Alle WLED Live Overrides zurücksetzen
        $this->ResetAllOverrides();

        // 4. Status setzen
        $this->SetValue('ShowActive', false);
        $this->SetValue('ActiveScene', '');

        // 5. ShowMode wieder freigeben
        IPS_SetDisabled($this->GetIDForIdent('ShowMode'), false);

        $this->SLogInfo('Audio-Show beendet');
    }

    // =========================================================================
    // WLED Steuerung
    // =========================================================================

    /**
     * Setzt den WLED Live Override (lor) für einen Controller.
     *
     * @param string $propertyName Name der Property mit der WLED-IP
     * @param bool   $override     true = Override an (lor=2), false = Override aus (lor=0)
     */
    private function SetWledOverride(string $propertyName, bool $override): void
    {
        $ip = trim($this->ReadPropertyString($propertyName));
        if (empty($ip)) {
            return;
        }

        $url = "http://{$ip}/json/state";
        // lor=2: Override permanent (WLED ignoriert eigenen Art-Net Output)
        // lor=0: Normal (WLED zeigt wieder eigene Effekte)
        $payload = ['lor' => $override ? 2 : 0];

        $result = $this->HttpRequest($url, 'POST', ['Content-Type: application/json'], $payload, 3);
        if ($result === null) {
            $this->SLogWarning('WLED nicht erreichbar: ' . $ip);
        } else {
            $this->SLogDebug('WLED lor=' . ($override ? '2' : '0') . ' gesetzt: ' . $ip);
        }
    }

    /**
     * Setzt alle WLED Live Overrides zurück.
     */
    private function ResetAllOverrides(): void
    {
        $this->SetWledOverride('WledIpWohnzimmer', false);
        $this->SetWledOverride('WledIpGarten', false);
    }

    // =========================================================================
    // LedFx API
    // =========================================================================

    /**
     * Gibt die Basis-URL der LedFx REST API zurück.
     */
    private function GetLedFxBaseUrl(): string
    {
        $host = trim($this->ReadPropertyString('LedFxHost'));
        $port = $this->ReadPropertyInteger('LedFxPort');
        return "http://{$host}:{$port}";
    }

    /**
     * Aktiviert eine LedFx-Szene über die REST API.
     *
     * PUT /api/scenes  {"id": "<sceneId>", "action": "activate"}
     */
    private function ActivateLedFxScene(string $sceneId): bool
    {
        $url = $this->GetLedFxBaseUrl() . '/api/scenes';
        $payload = [
            'id'     => $sceneId,
            'action' => 'activate'
        ];

        $result = $this->HttpRequest($url, 'PUT', ['Content-Type: application/json'], $payload, 5);

        if ($result === null) {
            $this->DA_SetAvailable(false, 'LedFx nicht erreichbar');
            return false;
        }

        $this->DA_SetAvailable(true);
        return true;
    }

    /**
     * Löscht alle aktiven Effekte auf allen LedFx Virtuals (Fallback wenn keine Off-Szene konfiguriert).
     */
    private function ClearAllLedFxEffects(): void
    {
        $url = $this->GetLedFxBaseUrl() . '/api/virtuals';
        $result = $this->HttpRequest($url, 'GET', [], null, 5);

        if ($result === null || !isset($result['virtuals'])) {
            $this->SLogWarning('Konnte LedFx Virtuals nicht abrufen');
            return;
        }

        foreach (array_keys($result['virtuals']) as $virtualId) {
            $deleteUrl = $this->GetLedFxBaseUrl() . '/api/virtuals/' . $virtualId . '/effects';
            $this->HttpRequest($deleteUrl, 'DELETE', [], null, 3);
        }
    }

    /**
     * Ruft alle verfügbaren LedFx-Szenen ab.
     *
     * @return array<string, array> Szenen-ID => Szenen-Daten
     */
    private function GetLedFxScenes(): array
    {
        $url = $this->GetLedFxBaseUrl() . '/api/scenes';
        $result = $this->HttpRequest($url, 'GET', [], null, 5);

        if ($result === null || !isset($result['scenes'])) {
            return [];
        }

        return $result['scenes'];
    }

    // =========================================================================
    // Health Check
    // =========================================================================

    /**
     * Prüft die Erreichbarkeit der LedFx API und aktualisiert Statusvariablen.
     * Wird zyklisch per Timer aufgerufen.
     */
    public function HealthCheck(): void
    {
        $url = $this->GetLedFxBaseUrl() . '/api/info';
        $result = $this->HttpRequest($url, 'GET', [], null, 5);

        if ($result === null) {
            $this->DA_SetAvailable(false, 'LedFx nicht erreichbar');
            $this->SetValue('LedFxVersion', '');
            return;
        }

        $this->DA_SetAvailable(true);

        // Version auslesen
        $version = $result['version'] ?? '';
        $this->SetValue('LedFxVersion', $version);
    }

    // =========================================================================
    // Configuration Form
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        // Szenen dynamisch laden für Dropdown-Auswahl
        $sceneOptions = [['caption' => '--- Bitte waehlen ---', 'value' => '']];
        $host = trim($this->ReadPropertyString('LedFxHost'));

        if (!empty($host)) {
            $scenes = $this->GetLedFxScenes();
            foreach ($scenes as $sceneId => $sceneData) {
                $name = $sceneData['name'] ?? $sceneId;
                $sceneOptions[] = [
                    'caption' => $name,
                    'value'   => $sceneId
                ];
            }
        }

        return json_encode([
            'status' => [
                [
                    'code'    => 104,
                    'icon'    => 'inactive',
                    'caption' => 'Kein LedFx Host konfiguriert'
                ]
            ],
            'elements' => [
                // --- LedFx Verbindung ---
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'LedFx Verbindung',
                    'expanded' => true,
                    'items'   => [
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'LedFxHost',
                            'caption' => 'LedFx Host (IP oder Hostname)',
                            'width'   => '300px'
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'LedFxPort',
                            'caption' => 'LedFx Port',
                            'minimum' => 1,
                            'maximum' => 65535
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'PollInterval',
                            'caption' => 'Health-Check Intervall (Sekunden)',
                            'minimum' => 0,
                            'maximum' => 3600,
                            'suffix'  => 's'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Verbindung pruefen',
                            'onClick' => 'SLFX_HealthCheck($id);'
                        ]
                    ]
                ],

                // --- LedFx Szenen ---
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'LedFx Szenen',
                    'expanded' => true,
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Weise jedem Show-Modus eine LedFx-Szene zu. Die Szenen muessen zuerst in der LedFx Web-UI erstellt werden.'
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'SceneWohnzimmer',
                            'caption' => 'Szene: Nur Wohnzimmer',
                            'options' => $sceneOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'SceneGarten',
                            'caption' => 'Szene: Nur Garten',
                            'options' => $sceneOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'SceneBeides',
                            'caption' => 'Szene: Beides (gleicher Effekt)',
                            'options' => $sceneOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'SceneFreqSplit',
                            'caption' => 'Szene: Frequency Split (WZ=Bass, Garten=Mitten)',
                            'options' => $sceneOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'SceneOff',
                            'caption' => 'Szene: Show beenden (Alles aus)',
                            'options' => $sceneOptions
                        ]
                    ]
                ],

                // --- WLED Controller ---
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'WLED Controller',
                    'expanded' => true,
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'IP-Adressen der WLED Controller, die bei aktiver Show per Live Override (lor) gemutet werden sollen.'
                        ],
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'WledIpWohnzimmer',
                            'caption' => 'WLED IP Wohnzimmer',
                            'width'   => '200px'
                        ],
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'WledIpGarten',
                            'caption' => 'WLED IP Garten',
                            'width'   => '200px'
                        ]
                    ]
                ],

                // --- Alarm ---
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Alarm',
                    'expanded' => false,
                    'items'   => [
                        [
                            'type'    => 'Select',
                            'name'    => 'AvailabilityAlarmPriority',
                            'caption' => 'Alarm bei LedFx Ausfall',
                            'options' => [
                                ['caption' => 'Kein Alarm',     'value' => -1],
                                ['caption' => 'Niedrig (Low)',  'value' => 0],
                                ['caption' => 'Mittel (Medium)','value' => 1],
                                ['caption' => 'Hoch (High)',    'value' => 2]
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    }
}
