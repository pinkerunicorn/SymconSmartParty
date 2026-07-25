<?php

declare(strict_types=1);

class SmartPartyManager extends IPSModuleStrict
{
    // =========================================================================
    // Lifecycle
    // =========================================================================

    public function Create(): void
    {
        parent::Create();

        // Gateway & SMTP Verknüpfung
        $this->RegisterPropertyInteger('GatewayInstance', 0);
        $this->RegisterPropertyInteger('SMTPInstance', 0);

        // WAHA
        $this->RegisterPropertyString('WAHABaseURL', 'http://localhost:3000');
        $this->RegisterPropertyString('WAHAApiKey', '');
        $this->RegisterPropertyString('WAHASession', 'default');

        // Gastgeber
        $this->RegisterPropertyString('HostName', '');

        // E-Mail Template
        $this->RegisterPropertyString('EmailSubject', 'Einladung: {EventName} 🎉');
        $this->RegisterPropertyString('EmailTemplate',
            "Liebe(r) {GuestName},\n\nwir laden dich herzlich zu unserer Gartenparty \"{EventName}\" ein!\n\n" .
            "📅 Wann: {EventDate} um {EventTime} Uhr\n📍 Wo: {EventLocation}\n\n" .
            "Bitte gib uns über folgenden Link Bescheid, ob du kommen kannst:\n{RSVPLink}\n\n" .
            "Wir freuen uns auf dich!\nLiebe Grüße, {HostName}"
        );

        // WhatsApp Template
        $this->RegisterPropertyString('WhatsAppTemplate',
            "Hallo {GuestName}! 🎉\n\nDu bist herzlich eingeladen zu unserer Gartenparty \"{EventName}\"!\n\n" .
            "📅 {EventDate} um {EventTime} Uhr\n📍 {EventLocation}\n\n" .
            "Rückmeldung bitte hier: {RSVPLink}\n\nLiebe Grüße, {HostName}"
        );

        // RSVP
        $this->RegisterPropertyString('RSVPFormURL', '');
        $this->RegisterPropertyString('RSVPYesValue', 'Ja');
        $this->RegisterPropertyInteger('RSVPCheckInterval', 60);

        // Event-Daten (JSON) — persistent gespeichert
        $this->RegisterAttributeString('EventData', '{}');

        // Symcon-Variablen
        $this->RegisterVariableBoolean('EventActive', 'Event aktiv', '', 0);
        $this->RegisterVariableString('EventName', 'Event Name', '', 1);
        $this->RegisterVariableString('EventDate', 'Datum & Uhrzeit', '', 2);
        $this->RegisterVariableString('EventLocation', 'Ort', '', 3);
        $this->RegisterVariableInteger('TotalGuests', 'Gaeste gesamt', '', 4);
        $this->RegisterVariableInteger('ConfirmedGuests', 'Zusagen', '', 5);
        $this->RegisterVariableInteger('DeclinedGuests', 'Absagen', '', 6);
        $this->RegisterVariableInteger('PendingGuests', 'Ausstehend', '', 7);
        $this->RegisterVariableString('GuestListHTML', 'Gaesteliste', '', 8);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('GuestListHTML'), [
            'type' => 'HTML',
        ]);

        // Timer für periodische RSVP-Prüfung
        $this->RegisterTimer('RSVPCheckTimer', 0, 'SPM_CheckRSVP($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Referenzen registrieren
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $gatewayId = $this->ReadPropertyInteger('GatewayInstance');
        if ($gatewayId > 1 && @IPS_ObjectExists($gatewayId)) {
            $this->RegisterReference($gatewayId);
        }
        $smtpId = $this->ReadPropertyInteger('SMTPInstance');
        if ($smtpId > 1 && @IPS_ObjectExists($smtpId)) {
            $this->RegisterReference($smtpId);
        }

        // RSVP Timer konfigurieren
        $interval = $this->ReadPropertyInteger('RSVPCheckInterval');
        if ($this->GetValue('EventActive') && $interval > 0) {
            $this->SetTimerInterval('RSVPCheckTimer', $interval * 60 * 1000);
        } else {
            $this->SetTimerInterval('RSVPCheckTimer', 0);
        }

        // Gästeliste aktualisieren
        $this->UpdateDisplayVariables();
    }

    // =========================================================================
    // Configuration Form
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        $eventData = $this->GetEventData();
        $hasEvent  = !empty($eventData['event']['name'] ?? '');
        $hasGuests = !empty($eventData['guests'] ?? []);

        return json_encode([
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => '── Verknüpfungen ──────────────────────────────────',
                ],
                [
                    'type'    => 'SelectInstance',
                    'name'    => 'GatewayInstance',
                    'caption' => 'SmartPartyGateway Instanz',
                    'filter'  => '{2F9E1C8A-4D3B-4A7E-B6C9-5F2A8D1E3C7B}',
                ],
                [
                    'type'    => 'SelectInstance',
                    'name'    => 'SMTPInstance',
                    'caption' => 'IP-Symcon SMTP Instanz',
                ],
                // WAHA
                [
                    'type'    => 'Label',
                    'caption' => '── WhatsApp (WAHA) ────────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'WAHABaseURL',
                    'caption' => 'WAHA URL (z.B. http://192.168.1.100:3000)',
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'WAHAApiKey',
                    'caption' => 'WAHA API Key',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'WAHASession',
                    'caption' => 'WAHA Session Name',
                ],
                // Gastgeber
                [
                    'type'    => 'Label',
                    'caption' => '── Einladung ──────────────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'HostName',
                    'caption' => 'Name des Gastgebers (für Templates)',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'EmailSubject',
                    'caption' => 'E-Mail Betreff',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'E-Mail Text (Platzhalter: {GuestName} {EventName} {EventDate} {EventTime} {EventLocation} {RSVPLink} {HostName})',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'EmailTemplate',
                    'caption' => 'E-Mail Template',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'WhatsApp Text (gleiche Platzhalter)',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'WhatsAppTemplate',
                    'caption' => 'WhatsApp Template',
                ],
                // RSVP
                [
                    'type'    => 'Label',
                    'caption' => '── RSVP Google Form ───────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'RSVPFormURL',
                    'caption' => 'Google Form URL (komplette URL)',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'RSVPYesValue',
                    'caption' => 'Text der "Ja"-Antwort im Form (z.B. "Ja, ich komme!")',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'RSVPCheckInterval',
                    'caption' => 'RSVP-Prüfintervall (Minuten)',
                    'minimum' => 5,
                    'maximum' => 1440,
                ],
            ],
            'actions' => [
                [
                    'type'    => 'Label',
                    'caption' => '── Event erstellen ────────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'NewEventName',
                    'caption' => 'Event Name (z.B. Sommerfest 2026)',
                    'value'   => '',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'NewEventDate',
                    'caption' => 'Datum (z.B. 15.08.2026)',
                    'value'   => '',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'NewEventTime',
                    'caption' => 'Uhrzeit (z.B. 16:00)',
                    'value'   => '',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'NewEventLocation',
                    'caption' => 'Ort',
                    'value'   => '',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🎉 Event erstellen',
                    'onClick' => 'SPM_CreateEvent($id, $NewEventName, $NewEventDate, $NewEventTime, $NewEventLocation);',
                ],
                [
                    'type'    => 'Label',
                    'caption' => '── Gäste & Einladungen ────────────────────────────',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '👥 Gäste aus Google Contacts laden',
                    'onClick' => 'SPM_LoadGuests($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '📨 Einladungen versenden (alle Kanäle)',
                    'onClick' => 'SPM_SendAllInvitations($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🔄 RSVP jetzt prüfen',
                    'onClick' => 'SPM_CheckRSVP($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🔔 Erinnerung senden (nur ausstehende)',
                    'onClick' => 'SPM_SendReminders($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🗑️ Event zurücksetzen',
                    'onClick' => 'SPM_ResetEvent($id);',
                ],
            ],
        ]);
    }

    // =========================================================================
    // Public Functions (SPM_* Prefix)
    // =========================================================================

    public function CreateEvent(string $name, string $date, string $time, string $location): void
    {
        if (empty($name) || empty($date)) {
            echo 'Fehler: Name und Datum sind Pflichtfelder.';
            return;
        }

        $formUrl = $this->ReadPropertyString('RSVPFormURL');
        $formId  = $this->ExtractFormId($formUrl);

        $eventData = [
            'event' => [
                'name'     => $name,
                'date'     => $date,
                'time'     => $time,
                'location' => $location,
                'formId'   => $formId,
                'formUrl'  => $formUrl,
            ],
            'guests' => [],
        ];

        $this->WriteAttributeString('EventData', json_encode($eventData, JSON_UNESCAPED_UNICODE));
        $this->UpdateDisplayVariables();

        // RSVP Timer starten
        $interval = $this->ReadPropertyInteger('RSVPCheckInterval');
        $this->SetTimerInterval('RSVPCheckTimer', $interval * 60 * 1000);

        echo "✅ Event \"{$name}\" erstellt!\nJetzt Gäste laden mit [Gäste aus Google Contacts laden].";
    }

    public function LoadGuests(): void
    {
        $gatewayId = $this->ReadPropertyInteger('GatewayInstance');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            echo 'Fehler: SmartPartyGateway Instanz nicht konfiguriert.';
            return;
        }

        $guests = SPG_FetchGuests($gatewayId);

        if (empty($guests)) {
            echo 'Keine Gäste gefunden. Prüfe die Google Contacts Labels im SmartPartyGateway.';
            return;
        }

        $eventData = $this->GetEventData();
        $eventData['guests'] = $guests;
        $this->WriteAttributeString('EventData', json_encode($eventData, JSON_UNESCAPED_UNICODE));
        $this->UpdateDisplayVariables();

        echo "✅ " . count($guests) . " Gäste geladen:\n";
        foreach ($guests as $g) {
            $channelIcon = match ($g['channel']) {
                'email'    => '📧',
                'whatsapp' => '💬',
                'both'     => '📧💬',
                default    => '?',
            };
            echo "  {$channelIcon} {$g['name']}";
            if ($g['email']) {
                echo " <{$g['email']}>";
            }
            if ($g['phone']) {
                echo " / {$g['phone']}";
            }
            echo "\n";
        }
    }

    public function SendAllInvitations(): void
    {
        $eventData = $this->GetEventData();
        $guests    = $eventData['guests'] ?? [];
        $event     = $eventData['event'] ?? [];

        if (empty($event['name'] ?? '')) {
            echo 'Fehler: Kein aktives Event. Bitte zuerst ein Event erstellen.';
            return;
        }
        if (empty($guests)) {
            echo 'Fehler: Keine Gäste geladen. Bitte zuerst Gäste laden.';
            return;
        }

        $sent    = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($guests as &$guest) {
            $channel = $guest['channel'];

            // E-Mail senden
            if (in_array($channel, ['email', 'both'])) {
                if (!empty($guest['email'])) {
                    if ($this->SendEmailToGuest($guest, $event)) {
                        if (!in_array('email', $guest['invitedVia'])) {
                            $guest['invitedVia'][] = 'email';
                        }
                        $sent++;
                    } else {
                        $errors++;
                    }
                } else {
                    $this->SendDebug('SendAll', 'No email for: ' . $guest['name'], 0);
                    $skipped++;
                }
            }

            // WhatsApp senden
            if (in_array($channel, ['whatsapp', 'both'])) {
                if (!empty($guest['phone'])) {
                    if ($this->SendWhatsAppToGuest($guest, $event)) {
                        if (!in_array('whatsapp', $guest['invitedVia'])) {
                            $guest['invitedVia'][] = 'whatsapp';
                        }
                        $sent++;
                    } else {
                        $errors++;
                    }
                } else {
                    $this->SendDebug('SendAll', 'No phone for: ' . $guest['name'], 0);
                    $skipped++;
                }
            }

            $guest['invitedAt'] = date('d.m.Y H:i');
        }
        unset($guest);

        $eventData['guests'] = $guests;
        $this->WriteAttributeString('EventData', json_encode($eventData, JSON_UNESCAPED_UNICODE));
        $this->SetValue('EventActive', true);
        $this->UpdateDisplayVariables();

        echo "✅ Einladungen versendet: {$sent} erfolgreich, {$skipped} übersprungen, {$errors} Fehler.";
    }

    public function SendReminders(): void
    {
        $eventData = $this->GetEventData();
        $guests    = $eventData['guests'] ?? [];
        $event     = $eventData['event'] ?? [];

        $pending = array_filter($guests, fn($g) => $g['status'] === 'pending' && !empty($g['invitedAt']));

        if (empty($pending)) {
            echo 'Keine ausstehenden Rückmeldungen — alle Gäste haben bereits geantwortet oder wurden noch nicht eingeladen.';
            return;
        }

        $sent = 0;
        foreach ($pending as &$guest) {
            $channel = $guest['channel'];

            if (in_array($channel, ['email', 'both']) && !empty($guest['email'])) {
                $this->SendEmailToGuest($guest, $event, isReminder: true);
                $sent++;
            }
            if (in_array($channel, ['whatsapp', 'both']) && !empty($guest['phone'])) {
                $this->SendWhatsAppToGuest($guest, $event, isReminder: true);
                $sent++;
            }
        }
        unset($guest);

        echo "✅ Erinnerungen versendet an " . count($pending) . " Gäste ({$sent} Nachrichten).";
    }

    public function CheckRSVP(): void
    {
        $eventData = $this->GetEventData();
        $guests    = $eventData['guests'] ?? [];
        $formId    = $eventData['event']['formId'] ?? '';

        if (empty($formId) || empty($guests)) {
            $this->SendDebug('CheckRSVP', 'No formId or no guests', 0);
            return;
        }

        $gatewayId = $this->ReadPropertyInteger('GatewayInstance');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            return;
        }

        $responses = SPG_GetRSVPResponses($gatewayId, $formId);
        if (empty($responses)) {
            $this->SendDebug('CheckRSVP', 'No responses yet', 0);
            return;
        }

        $yesValue = $this->ReadPropertyString('RSVPYesValue');
        $updated  = 0;

        // Responses nach E-Mail indexieren
        $responseByEmail = [];
        foreach ($responses as $response) {
            $email = strtolower($response['respondentEmail'] ?? '');
            if ($email) {
                $responseByEmail[$email] = $response;
            }
        }

        foreach ($guests as &$guest) {
            if ($guest['status'] !== 'pending') {
                continue;
            }

            $email = strtolower($guest['email'] ?? '');
            if (empty($email) || !isset($responseByEmail[$email])) {
                continue;
            }

            $response = $responseByEmail[$email];
            $answered = false;
            $isYes    = false;

            // Erste Antwort aus dem Form auswerten
            $answers = $response['answers'] ?? [];
            foreach ($answers as $answer) {
                $textAnswers = $answer['textAnswers']['answers'] ?? [];
                foreach ($textAnswers as $ta) {
                    $value = $ta['value'] ?? '';
                    if (!empty($value)) {
                        $answered = true;
                        // Prüfen ob "Ja" enthalten
                        if (stripos($value, $yesValue) !== false) {
                            $isYes = true;
                        }
                        break 2;
                    }
                }
            }

            if ($answered) {
                $guest['status']      = $isYes ? 'confirmed' : 'declined';
                $guest['respondedAt'] = date('d.m.Y H:i');
                $updated++;
                $this->SendDebug('CheckRSVP', $guest['name'] . ' → ' . $guest['status'], 0);
            }
        }
        unset($guest);

        if ($updated > 0) {
            $eventData['guests'] = $guests;
            $this->WriteAttributeString('EventData', json_encode($eventData, JSON_UNESCAPED_UNICODE));
            $this->UpdateDisplayVariables();
            $this->SendDebug('CheckRSVP', $updated . ' response(s) updated', 0);
        }
    }

    public function GetGuestList(): array
    {
        $data = $this->GetEventData();
        return $data['guests'] ?? [];
    }

    public function ResetEvent(): void
    {
        $this->WriteAttributeString('EventData', '{}');
        $this->SetValue('EventActive', false);
        $this->SetValue('EventName', '');
        $this->SetValue('EventDate', '');
        $this->SetValue('EventLocation', '');
        $this->SetValue('TotalGuests', 0);
        $this->SetValue('ConfirmedGuests', 0);
        $this->SetValue('DeclinedGuests', 0);
        $this->SetValue('PendingGuests', 0);
        $this->SetValue('GuestListHTML', '');
        $this->SetTimerInterval('RSVPCheckTimer', 0);
        echo '✅ Event wurde zurückgesetzt.';
    }

    // =========================================================================
    // Private — Nachrichtenversand
    // =========================================================================

    private function SendEmailToGuest(array $guest, array $event, bool $isReminder = false): bool
    {
        $smtpId = $this->ReadPropertyInteger('SMTPInstance');
        if ($smtpId <= 0 || !IPS_InstanceExists($smtpId)) {
            $this->SendDebug('SendEmail', 'No SMTP instance configured', 0);
            return false;
        }

        $subject = $this->FillTemplate($this->ReadPropertyString('EmailSubject'), $guest, $event);
        $body    = $this->FillTemplate($this->ReadPropertyString('EmailTemplate'), $guest, $event);

        if ($isReminder) {
            $subject = '⏰ Erinnerung: ' . $subject;
            $body    = "Hallo {$guest['name']}, wir warten noch auf deine Rückmeldung!\n\n" . $body;
        }

        try {
            SMTP_SendMail($smtpId, $guest['email'], $subject, $body);
            $this->SendDebug('SendEmail', 'Sent to: ' . $guest['email'], 0);
            return true;
        } catch (Exception $e) {
            $this->SendDebug('SendEmail', 'Error: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function SendWhatsAppToGuest(array $guest, array $event, bool $isReminder = false): bool
    {
        $wahaUrl    = rtrim($this->ReadPropertyString('WAHABaseURL'), '/');
        $wahaKey    = $this->ReadPropertyString('WAHAApiKey');
        $session    = $this->ReadPropertyString('WAHASession');
        $phone      = $guest['phone'];

        if (empty($phone)) {
            return false;
        }

        $text = $this->FillTemplate($this->ReadPropertyString('WhatsAppTemplate'), $guest, $event);

        if ($isReminder) {
            $text = "⏰ Erinnerung: Wir warten noch auf deine Rückmeldung!\n\n" . $text;
        }

        $payload = json_encode([
            'session' => $session,
            'chatId'  => $phone . '@c.us',
            'text'    => $text,
        ]);

        $headers = ['Content-Type: application/json'];
        if (!empty($wahaKey)) {
            $headers[] = 'X-Api-Key: ' . $wahaKey;
        }

        $ch = curl_init($wahaUrl . '/api/sendText');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            $this->SendDebug('SendWhatsApp', 'Sent to: ' . $phone, 0);
            return true;
        }

        $this->SendDebug('SendWhatsApp', 'Error HTTP ' . $httpCode . ': ' . $response, 0);
        return false;
    }

    // =========================================================================
    // Private — Hilfsfunktionen
    // =========================================================================

    private function FillTemplate(string $template, array $guest, array $event): string
    {
        $placeholders = [
            '{GuestName}'     => $guest['name'] ?? '',
            '{EventName}'     => $event['name'] ?? '',
            '{EventDate}'     => $event['date'] ?? '',
            '{EventTime}'     => $event['time'] ?? '',
            '{EventLocation}' => $event['location'] ?? '',
            '{RSVPLink}'      => $event['formUrl'] ?? '',
            '{HostName}'      => $this->ReadPropertyString('HostName'),
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    private function ExtractFormId(string $formUrl): string
    {
        // https://docs.google.com/forms/d/e/1FAIpQLSe.../viewform → ID extrahieren
        if (preg_match('/\/forms\/d\/e\/([^\/]+)/', $formUrl, $m)) {
            return $m[1];
        }
        if (preg_match('/\/forms\/d\/([^\/]+)/', $formUrl, $m)) {
            return $m[1];
        }
        return '';
    }

    private function GetEventData(): array
    {
        $raw = $this->ReadAttributeString('EventData');
        if (empty($raw) || $raw === '{}') {
            return ['event' => [], 'guests' => []];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['event' => [], 'guests' => []];
    }

    private function UpdateDisplayVariables(): void
    {
        $data   = $this->GetEventData();
        $event  = $data['event'] ?? [];
        $guests = $data['guests'] ?? [];

        $total     = count($guests);
        $confirmed = count(array_filter($guests, fn($g) => $g['status'] === 'confirmed'));
        $declined  = count(array_filter($guests, fn($g) => $g['status'] === 'declined'));
        $pending   = count(array_filter($guests, fn($g) => $g['status'] === 'pending'));

        $this->SetValue('EventName', $event['name'] ?? '');
        $this->SetValue('EventDate', ($event['date'] ?? '') . (!empty($event['time']) ? ' · ' . $event['time'] . ' Uhr' : ''));
        $this->SetValue('EventLocation', $event['location'] ?? '');
        $this->SetValue('TotalGuests', $total);
        $this->SetValue('ConfirmedGuests', $confirmed);
        $this->SetValue('DeclinedGuests', $declined);
        $this->SetValue('PendingGuests', $pending);
        $this->SetValue('GuestListHTML', $this->BuildGuestListHTML($guests, $event));
    }

    private function BuildGuestListHTML(array $guests, array $event): string
    {
        if (empty($guests)) {
            return '<div style="font-family:sans-serif;padding:20px;color:#888;">Noch keine Gäste geladen.</div>';
        }

        $eventTitle = htmlspecialchars($event['name'] ?? '');
        $eventDate  = htmlspecialchars(($event['date'] ?? '') . (!empty($event['time']) ? ' · ' . $event['time'] . ' Uhr' : ''));
        $eventLoc   = htmlspecialchars($event['location'] ?? '');

        $rows = '';
        foreach ($guests as $g) {
            $statusIcon  = match ($g['status']) {
                'confirmed' => '🟢',
                'declined'  => '🔴',
                default     => '🟡',
            };
            $statusText = match ($g['status']) {
                'confirmed' => 'Zugesagt',
                'declined'  => 'Abgesagt',
                default     => 'Ausstehend',
            };
            $statusColor = match ($g['status']) {
                'confirmed' => '#d4edda',
                'declined'  => '#f8d7da',
                default     => '#fff3cd',
            };
            $channelIcon = match ($g['channel']) {
                'email'    => '📧',
                'whatsapp' => '💬',
                'both'     => '📧 💬',
                default    => '?',
            };
            $invitedAt   = $g['invitedAt'] ? htmlspecialchars($g['invitedAt']) : '—';
            $respondedAt = $g['respondedAt'] ? htmlspecialchars($g['respondedAt']) : '—';
            $name        = htmlspecialchars($g['name']);
            $contact     = htmlspecialchars($g['email'] ?: $g['phone'] ?: '—');

            $rows .= "<tr style=\"background:{$statusColor};\">
                <td style=\"padding:8px 12px;\">{$statusIcon} <strong>{$name}</strong></td>
                <td style=\"padding:8px 12px;font-size:0.9em;color:#555;\">{$contact}</td>
                <td style=\"padding:8px 12px;text-align:center;\">{$channelIcon}</td>
                <td style=\"padding:8px 12px;text-align:center;\">{$statusText}</td>
                <td style=\"padding:8px 12px;text-align:center;font-size:0.85em;\">{$invitedAt}</td>
                <td style=\"padding:8px 12px;text-align:center;font-size:0.85em;\">{$respondedAt}</td>
            </tr>";
        }

        return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:900px;">
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 20px;border-radius:10px 10px 0 0;">
        <h2 style="margin:0;font-size:1.2em;">🎉 {$eventTitle}</h2>
        <p style="margin:4px 0 0;font-size:0.9em;opacity:0.9;">📅 {$eventDate} &nbsp;·&nbsp; 📍 {$eventLoc}</p>
    </div>
    <table style="width:100%;border-collapse:collapse;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
        <thead>
            <tr style="background:#4a5568;color:#fff;font-size:0.85em;">
                <th style="padding:8px 12px;text-align:left;">Name</th>
                <th style="padding:8px 12px;text-align:left;">Kontakt</th>
                <th style="padding:8px 12px;text-align:center;">Kanal</th>
                <th style="padding:8px 12px;text-align:center;">Status</th>
                <th style="padding:8px 12px;text-align:center;">Eingeladen</th>
                <th style="padding:8px 12px;text-align:center;">Geantwortet</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
HTML;
    }
}
