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

        // LedFx Virtuals für Helligkeitssteuerung
        $this->RegisterPropertyString('LedFxVirtualWohnzimmer', '');
        $this->RegisterPropertyString('LedFxVirtualGarten', '');

        // Lyngdorf Verknüpfung
        $this->RegisterPropertyInteger('LyngdorfInstance', 0);

        // Health-Check Intervall
        $this->RegisterPropertyInteger('PollInterval', 30);

        // --- Variablen ---

        // Hauptschalter
        $this->RegisterVariableBoolean('ShowActive', 'Audio-Show', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'music'
        ], 1);

        // Show-Modus (Dropdown)
        $this->RegisterVariableInteger('ShowMode', 'Show-Modus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode([
                ['Value' => self::MODE_WOHNZIMMER, 'Caption' => 'Nur Wohnzimmer', 'IconActive' => true, 'IconValue' => 'Sofa',      'Color' => 0x3399FF],
                ['Value' => self::MODE_GARTEN,     'Caption' => 'Nur Garten',     'IconActive' => true, 'IconValue' => 'Tree',      'Color' => 0x33CC33],
                ['Value' => self::MODE_BEIDES,     'Caption' => 'Beides',         'IconActive' => true, 'IconValue' => 'lightbulb',     'Color' => 0xFF9900],
                ['Value' => self::MODE_FREQ_SPLIT, 'Caption' => 'Frequency Split','IconActive' => true, 'IconValue' => 'Frequency', 'Color' => 0xFF00FF]
            ])
        ], 2);

        // Helligkeit Wohnzimmer
        $this->RegisterVariableInteger('BrightnessWohnzimmer', 'Helligkeit Wohnzimmer', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'sun',
            'SUFFIX'       => '%',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP'         => 1
        ], 3);
        $this->EnableAction('BrightnessWohnzimmer');

        // Helligkeit Garten
        $this->RegisterVariableInteger('BrightnessGarten', 'Helligkeit Garten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'sun',
            'SUFFIX'       => '%',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP'         => 1
        ], 4);
        $this->EnableAction('BrightnessGarten');

        // Aktive Szene (read-only)
        $this->RegisterVariableString('ActiveScene', 'Aktive Szene', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'clapperboard'
        ], 10);

        // LedFx Version (read-only)
        $this->RegisterVariableString('LedFxVersion', 'LedFx Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'microchip'
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

        // ---------------------------------------------------------
        // Event-Registrierung: Lyngdorf Zone B Sync
        // ---------------------------------------------------------
        
        // Zuerst alle alten Registrierungen löschen
        foreach ($this->GetMessageList() as $senderId => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderId, $message);
            }
        }

        // Neue Registrierung (falls konfiguriert)
        $lyngdorfId = $this->ReadPropertyInteger('LyngdorfInstance');
        if ($lyngdorfId > 0 && IPS_InstanceExists($lyngdorfId)) {
            $powerVarId = @IPS_GetObjectIDByIdent('ZoneBPower', $lyngdorfId);
            if ($powerVarId !== false) {
                $this->RegisterMessage($powerVarId, VM_UPDATE);
                $this->SLogDebug("Registriere Event auf Lyngdorf Zone B (VarID: {$powerVarId})");
            }
        }
    }

    // =========================================================================
    // Actions & Events
    // =========================================================================

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message === VM_UPDATE) {
            $lyngdorfId = $this->ReadPropertyInteger('LyngdorfInstance');
            if ($lyngdorfId > 0) {
                $powerVarId = @IPS_GetObjectIDByIdent('ZoneBPower', $lyngdorfId);
                if ($SenderID === $powerVarId) {
                    $zoneBState = $Data[0]; // Neuer Wert (true/false)
                    $showActive = $this->GetValue('ShowActive');

                    if ($zoneBState && !$showActive) {
                        $this->SLogInfo('Lyngdorf Zone B extern eingeschaltet -> Starte LedFx Show');
                        // Wir rufen StartShow direkt auf, da wir den Status übernehmen wollen
                        $this->StartShow();
                    } elseif (!$zoneBState && $showActive) {
                        $this->SLogInfo('Lyngdorf Zone B extern ausgeschaltet -> Beende LedFx Show');
                        $this->StopShow();
                    }
                }
            }
        }
    }

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

            case 'BrightnessWohnzimmer':
                $this->SetValue('BrightnessWohnzimmer', $Value);
                $this->SetLedFxVirtualBrightness('LedFxVirtualWohnzimmer', $Value);
                break;

            case 'BrightnessGarten':
                $this->SetValue('BrightnessGarten', $Value);
                $this->SetLedFxVirtualBrightness('LedFxVirtualGarten', $Value);
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

        // 1. Modus und zugehörige Szene ermitteln
        switch ($mode) {
            case self::MODE_WOHNZIMMER:
                $sceneProperty = 'SceneWohnzimmer';
                break;

            case self::MODE_GARTEN:
                $sceneProperty = 'SceneGarten';
                break;

            case self::MODE_BEIDES:
                $sceneProperty = 'SceneBeides';
                break;

            case self::MODE_FREQ_SPLIT:
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
            return;
        }

        // 3. Status setzen
        $this->SetValue('ShowActive', true);
        $this->SetValue('ActiveScene', $sceneId);

        // 4. ShowMode ausgrauen
        IPS_SetDisabled($this->GetIDForIdent('ShowMode'), true);

        // 5. Lyngdorf Zone B einschalten (falls konfiguriert)
        $lyngdorfId = $this->ReadPropertyInteger('LyngdorfInstance');
        if ($lyngdorfId > 0 && IPS_InstanceExists($lyngdorfId)) {
            $powerVarId = @IPS_GetObjectIDByIdent('ZoneBPower', $lyngdorfId);
            if ($powerVarId !== false) {
                RequestAction($powerVarId, true);
                $this->SLogInfo('Lyngdorf Zone B eingeschaltet.');
            } else {
                $this->SLogWarning('Lyngdorf ZoneBPower Variable nicht gefunden.');
            }
        }

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

        // 3. Status setzen
        $this->SetValue('ShowActive', false);
        $this->SetValue('ActiveScene', '');

        // 5. ShowMode wieder freigeben
        IPS_SetDisabled($this->GetIDForIdent('ShowMode'), false);

        // 6. Lyngdorf Zone B ausschalten (falls konfiguriert)
        $lyngdorfId = $this->ReadPropertyInteger('LyngdorfInstance');
        if ($lyngdorfId > 0 && IPS_InstanceExists($lyngdorfId)) {
            $powerVarId = @IPS_GetObjectIDByIdent('ZoneBPower', $lyngdorfId);
            if ($powerVarId !== false) {
                RequestAction($powerVarId, false);
                $this->SLogInfo('Lyngdorf Zone B ausgeschaltet.');
            }
        }

        $this->SLogInfo('Audio-Show beendet');
    }

    // =========================================================================
    // LedFx API
    // =========================================================================

    /**
     * Setzt die Helligkeit eines LedFx Virtuals (0-100% -> 0.0 - 1.0).
     */
    private function SetLedFxVirtualBrightness(string $propertyName, int $brightnessPercent): void
    {
        $virtualId = trim($this->ReadPropertyString($propertyName));
        if (empty($virtualId)) {
            return;
        }

        $url = $this->GetLedFxBaseUrl() . '/api/virtuals/' . $virtualId;
        
        // 1. Aktuelle Config des Virtuals abrufen
        $result = $this->HttpRequest($url, 'GET', [], null, 3);
        if ($result === null || !isset($result['virtual'])) {
            $this->SLogWarning('LedFx Virtual nicht gefunden: ' . $virtualId);
            return;
        }

        $virtualData = $result['virtual'];
        $config = $virtualData['config'] ?? [];
        $active = $virtualData['active'] ?? true;

        // 2. max_brightness anpassen (0.0 bis 1.0)
        $maxBrightness = round($brightnessPercent / 100, 2);
        $config['max_brightness'] = $maxBrightness;

        $payload = [
            'config' => $config,
            'active' => $active
        ];

        // 3. Aktualisierte Config senden
        $updateResult = $this->HttpRequest($url, 'PUT', ['Content-Type: application/json'], $payload, 3);
        if ($updateResult === null) {
            $this->SLogWarning('Konnte LedFx Virtual Helligkeit nicht setzen: ' . $virtualId);
        } else {
            $this->SLogDebug("LedFx Virtual '{$virtualId}' Helligkeit auf {$brightnessPercent}% gesetzt.");
        }
    }

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

    private function GetLedFxVirtuals(): array
    {
        $url = $this->GetLedFxBaseUrl() . '/api/virtuals';
        $result = $this->HttpRequest($url, 'GET', [], null, 5);

        if ($result === null || !isset($result['virtuals'])) {
            return [];
        }

        return $result['virtuals'];
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

        $virtualOptions = [['caption' => '--- Bitte waehlen ---', 'value' => '']];
        if (!empty($host)) {
            $virtuals = $this->GetLedFxVirtuals();
            foreach ($virtuals as $virtualId => $virtualData) {
                // Nur Haupt-Virtuals (die auf ein Device zeigen)
                if (isset($virtualData['is_device']) && $virtualData['is_device'] === $virtualId) {
                    $name = $virtualData['config']['name'] ?? $virtualId;
                    $virtualOptions[] = [
                        'caption' => $name,
                        'value'   => $virtualId
                    ];
                }
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

                // --- LedFx Zuordnung ---
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'LedFx Zuordnung (Helligkeit)',
                    'expanded' => true,
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Weise die LedFx Virtuals für Wohnzimmer und Garten zu, damit die Helligkeits-Slider funktionieren.'
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'LedFxVirtualWohnzimmer',
                            'caption' => 'Virtual: Wohnzimmer',
                            'options' => $virtualOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'LedFxVirtualGarten',
                            'caption' => 'Virtual: Garten',
                            'options' => $virtualOptions
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

                // --- Audio / Receiver ---
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Audio / Receiver',
                    'expanded' => true,
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Optional: Wähle die Lyngdorf MP-60 Instanz aus. Die Zone B wird dann automatisch beim Starten der Show ein- und beim Beenden ausgeschaltet.'
                        ],
                        [
                            'type'    => 'SelectInstance',
                            'name'    => 'LyngdorfInstance',
                            'caption' => 'Lyngdorf Instanz'
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
