<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartPartyManager extends IPSModuleStrict
{
    use SmartLog_Trait;

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
        $this->RegisterPropertyString('WAHABaseURL', 'http://127.0.0.1:3000');
        $this->RegisterPropertyString('WAHAApiKey', '');
        $this->RegisterPropertyString('WAHASession', 'default');

        // E-Mail Template
        $this->RegisterPropertyString('EmailSubject', 'Einladung!');
        $this->RegisterPropertyString('EmailTemplate', "Liebe(r) {GuestName},\n\nwir laden dich herzlich ein!\n\nLink: {RSVPLink}");

        // WhatsApp Template
        $this->RegisterPropertyString('WhatsAppTemplate', "Hallo {GuestName}! 🎉\n\nDu bist herzlich eingeladen!\n\nRückmeldung: {RSVPLink}");

        // RSVP
        $this->RegisterPropertyString('RSVPFormURL', '');
        $this->RegisterPropertyString('RSVPEmailEntry', '');
        $this->RegisterPropertyString('RSVPNameEntry', '');
        $this->RegisterPropertyString('RSVPYesValue', 'Ja');
        $this->RegisterPropertyInteger('RSVPCheckInterval', 60);

        // Event-Daten (JSON) — persistent gespeichert
        $this->RegisterAttributeString('EventData', '{}');

        // Variablen
        $this->RegisterVariableBoolean('EventActive', 'Event aktiv', '', 0);
        IPS_SetIcon($this->GetIDForIdent('EventActive'), 'Calendar');

        $this->RegisterVariableInteger('TotalGuests', 'Gäste gesamt', '', 4);
        IPS_SetIcon($this->GetIDForIdent('TotalGuests'), 'Persons');

        $this->RegisterVariableInteger('ConfirmedGuests', 'Zusagen', '', 5);
        IPS_SetIcon($this->GetIDForIdent('ConfirmedGuests'), 'Ok');

        $this->RegisterVariableInteger('DeclinedGuests', 'Absagen', '', 6);
        IPS_SetIcon($this->GetIDForIdent('DeclinedGuests'), 'Warning');

        $this->RegisterVariableInteger('PendingGuests', 'Ausstehend', '', 7);
        IPS_SetIcon($this->GetIDForIdent('PendingGuests'), 'Clock');

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

        // Alte Variablen aufräumen falls vorhanden
        $this->MaintainVariable('EventName', 'Event Name', 3, '', 1, false);
        $this->MaintainVariable('EventDate', 'Datum & Uhrzeit', 3, '', 2, false);
        $this->MaintainVariable('EventLocation', 'Ort', 3, '', 3, false);

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
        $event = $eventData['event'] ?? [];
        $hasEvent  = !empty($eventData['event']['name'] ?? '');
        $hasGuests = !empty($eventData['guests'] ?? []);

        $gatewayId = $this->ReadPropertyInteger('GatewayInstance');
        $webhookUrl = '';
        if ($gatewayId > 1 && @IPS_ObjectExists($gatewayId)) {
            $externalUrl = trim(IPS_GetProperty($gatewayId, 'SymconExternalURL'), '/');
            if (!empty($externalUrl)) {
                $webhookUrl = $externalUrl . '/hook/SmartPartyGateway';
            }
        }
        $scriptUrl = $webhookUrl ?: 'YOUR_SYMCON_WEBHOOK_URL';


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
                    'width'   => '100%',
                    'caption' => 'WAHA URL (z.B. http://192.168.1.100:3000)',
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'WAHAApiKey',
                    'width'   => '100%',
                    'caption' => 'WAHA API Key',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'WAHASession',
                    'width'   => '100%',
                    'caption' => 'WAHA Session Name',
                ],
                [
                    'type'    => 'Label',
                    'caption' => '── Einladung ──────────────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'EmailSubject',
                    'width'   => '100%',
                    'caption' => 'E-Mail Betreff',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'E-Mail Text (Platzhalter: {GuestName} {RSVPLink})',
                ],
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'EmailTemplate',
                    'width'     => '100%',
                    'caption'   => 'E-Mail Template',
                    'multiline' => true,
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'WhatsApp Text (gleiche Platzhalter)',
                ],
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'WhatsAppTemplate',
                    'width'     => '100%',
                    'caption'   => 'WhatsApp Template',
                    'multiline' => true,
                ],
                // RSVP
                [
                    'type'    => 'Label',
                    'caption' => '── RSVP Google Form ───────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'RSVPFormURL',
                    'width'   => '100%',
                    'caption' => 'Google Form URL (komplette URL)',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'RSVPEmailEntry',
                    'width'   => '100%',
                    'caption' => 'Prefill Entry ID für E-Mail (z.B. entry.123456789 - optional)',
                ],

                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'RSVPNameEntry',
                    'width'   => '100%',
                    'caption' => 'Prefill Entry ID für Vor- und Nachname (optional)',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'RSVPYesValue',
                    'width'   => '100%',
                    'caption' => 'Text der "Ja"-Antwort im Form (z.B. "Ja, ich komme!")',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'RSVPCheckInterval',
                    'caption' => 'RSVP-Prüfintervall (Minuten) - Optionaler Fallback',
                    'minimum' => 5,
                    'maximum' => 1440,
                ],
                [
                    'type'    => 'Label',
                    'caption' => '── Sofortiges RSVP per Webhook (Empfohlen) ────────',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Webhook-URL: ' . ($webhookUrl ?: 'Keine Gateway Instanz verknüpft oder SymconExternalURL leer'),
                ],
                [
                    'type'    => 'Label',
                    'caption' => "Apps Script Code for the Google Form:\nfunction onFormSubmit(e) {\n  UrlFetchApp.fetch('" . $scriptUrl . "', {method: 'post', payload: JSON.stringify(e.response.getItemResponses().map(r => ({q: r.getItem().getTitle(), a: r.getResponse()})))});\n}",
                ],
            ],

            'actions' => [
                [
                    'type'    => 'Label',
                    'caption' => '── Event erstellen ────────────────────────────────',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🎉 Event erstellen',
                    'onClick' => 'SPM_CreateEvent($id);',
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

    public function CreateEvent(): void
    {
        $formUrl = $this->ReadPropertyString('RSVPFormURL');
        $formId  = $this->ExtractFormId($formUrl);

        if (empty($formId)) {
            echo 'Fehler: Ungueltige Google Forms URL! Bitte verwende die URL aus dem Formular-EDITOR (z.B. https://docs.google.com/forms/d/1abc.../edit) - NICHT den "Senden"-Link (1FAIp...). Die API benoetigt die Editor-ID.';
            return;
        }

        $cleanViewformUrl = 'https://docs.google.com/forms/d/' . $formId . '/viewform';

        $eventData = [
            'event' => [
                'formId'   => $formId,
                'formUrl'  => $cleanViewformUrl,
            ],
            'guests' => [],
        ];

        $this->WriteAttributeString('EventData', json_encode($eventData, JSON_UNESCAPED_UNICODE));
        $this->UpdateDisplayVariables();

        // RSVP Timer starten
        $interval = $this->ReadPropertyInteger('RSVPCheckInterval');
        $this->SetTimerInterval('RSVPCheckTimer', $interval * 60 * 1000);

        echo "✅ Event erstellt!\nJetzt Gäste laden mit [Gäste aus Google Contacts laden].";
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

        if (empty($event['formId'] ?? '')) {
            echo 'Fehler: Kein aktives Event (Form ID fehlt). Bitte zuerst ein Event erstellen.';
            return;
        }
        if (empty($guests)) {
            echo 'Fehler: Keine Gäste geladen. Bitte zuerst Gäste laden.';
            return;
        }

        $sent       = 0;
        $skipped    = 0;
        $errors     = 0;
        $errorNames = [];

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
                        $errorNames[] = $guest['name'] . ' (E-Mail)';
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
                        $errorNames[] = $guest['name'] . ' (WhatsApp)';
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

        $summary = "Einladungen versendet: {$sent} erfolgreich, {$skipped} uebersprungen, {$errors} Fehler.";
        if (!empty($errorNames)) {
            $summary .= "\nFehler bei: " . implode(', ', $errorNames);
            $summary .= "\nDetails im Debug-Log der Instanz (Rechtsklick -> Debug).";
        }
        echo $summary;
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

        // Falls noch die falsche Responder-ID (1FAIp...) im Event gespeichert ist
        if (str_starts_with($formId, '1FAIp')) {
            // Versuche aktuelle Form URL aus den Settings zu nutzen
            $formUrl = $this->ReadPropertyString('RSVPFormURL');
            $newFormId = $this->ExtractFormId($formUrl);
            
            if (empty($newFormId)) {
                echo "Fehler: Das Event verwendet einen ungueltigen Form-Link (1FAIp...). \nBitte in der Konfiguration unter 'Google Form URL' den Link aus dem Formular-EDITOR eintragen (z.B. https://docs.google.com/forms/d/1abc.../edit) und dann das Event neu erstellen oder RSVP erneut pruefen.";
                return;
            }
            // Update the formId in the event data so it works next time
            $formId = $newFormId;
            $eventData['event']['formId'] = $newFormId;
            $eventData['event']['formUrl'] = 'https://docs.google.com/forms/d/' . $formId . '/viewform';
            $this->WriteAttributeString('EventData', json_encode($eventData, JSON_UNESCAPED_UNICODE));
        }

        if (empty($formId)) {
            echo "Fehler: Kein Formular konfiguriert. Bitte erstelle das Event neu (Klick auf 'Event erstellen').";
            return;
        }
        if (empty($guests)) {
            echo "Fehler: Keine Gäste geladen. Bitte lade zuerst die Gäste.";
            return;
        }

        $gatewayId = $this->ReadPropertyInteger('GatewayInstance');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            $this->SLog('WARNING', 'RSVP-Prüfung abgebrochen', 'GatewayInstance nicht konfiguriert (ID: ' . $gatewayId . ')');
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
            $details  = [];

            // Erste Antwort aus dem Form auswerten
            $answers = $response['answers'] ?? [];
            foreach ($answers as $answer) {
                $textAnswers = $answer['textAnswers']['answers'] ?? [];
                foreach ($textAnswers as $ta) {
                    $value = trim($ta['value'] ?? '');
                    if (!empty($value)) {
                        $answered = true;
                        // Pruefen ob "Ja" enthalten
                        if (stripos($value, $yesValue) !== false) {
                            $isYes = true;
                        } else {
                            // Andere Antworten sammeln, die nicht der Name oder die Mail sind
                            if (strtolower($value) !== strtolower($email) && strtolower($value) !== strtolower($guest['name'])) {
                                $details[] = $value;
                            }
                        }
                    }
                }
            }

            if ($answered) {
                $guest['status']      = $isYes ? 'confirmed' : 'declined';
                $guest['respondedAt'] = date('d.m.Y H:i');
                $guest['details']     = implode(', ', $details);
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
            $this->SLog('ERROR', 'E-Mail nicht gesendet', 'Keine SMTP-Instanz konfiguriert');
            return false;
        }

        $subject = $this->FillTemplate($this->ReadPropertyString('EmailSubject'), $guest, $event, false);
        $body    = $this->FillTemplate($this->ReadPropertyString('EmailTemplate'), $guest, $event, true);

        if ($isReminder) {
            $subject = '⏰ Erinnerung: ' . $subject;
            $body    = "Hallo {$guest['name']}, wir warten noch auf deine Rückmeldung!\n\n" . $body;
        }

        // Symcon 7.0+ sendet HTML nur, wenn <html> und </html> vorkommen.
        // Wir wandeln Zeilenumbrueche in <br> um und packen es in ein HTML-Geruest.
        $bodyHtml = "<html><body>\n" . nl2br($body) . "\n</body></html>";

        try {
            SMTP_SendMailEx($smtpId, $guest['email'], $subject, $bodyHtml);
            $this->SendDebug('SendEmail', 'Gesendet an: ' . $guest['email'], 0);
            return true;
        } catch (Exception $e) {
            $this->SendDebug('SendEmail', 'FEHLER fuer ' . $guest['name'] . ' (' . $guest['email'] . '): ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function SendWhatsAppToGuest(array $guest, array $event, bool $isReminder = false): bool
    {
        $wahaUrl    = rtrim($this->ReadPropertyString('WAHABaseURL'), '/');
        $wahaKey    = $this->ReadPropertyString('WAHAApiKey');
        $session    = $this->ReadPropertyString('WAHASession');
        $phone = $guest['phone'] ?? '';
        if (empty($phone)) {
            return false;
        }
        // Nummer bereinigen: nur Ziffern, kein führendes +
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Führende 00 durch direkte Ländervorwahl ersetzen
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        $chatId = $phone . '@c.us';

        // Prüfen ob Nummer auf WhatsApp existiert
        $checkUrl = $wahaUrl . '/api/contacts/check-exists?phone=' . urlencode($phone) . '&session=' . urlencode($session);
        $chk = curl_init($checkUrl);
        curl_setopt($chk, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chk, CURLOPT_TIMEOUT, 15);
        curl_setopt($chk, CURLOPT_HTTPHEADER, !empty($wahaKey) ? ['X-Api-Key: ' . $wahaKey, 'Accept: application/json'] : ['Accept: application/json']);
        $chkResponse = curl_exec($chk);
        $chkStatus = curl_getinfo($chk, CURLINFO_HTTP_CODE);
        curl_close($chk);

        $chkResult = json_decode($chkResponse, true);

        if ($chkStatus !== 200) {
            $this->SendDebug('SendWhatsApp', "WAHA check-exists API Fehler (HTTP $chkStatus): " . $chkResponse, 0);
            return false;
        }

        if (!($chkResult['numberExists'] ?? false)) {
            $this->SendDebug('SendWhatsApp', 'Nummer nicht auf WhatsApp: ' . $phone, 0);
            return false;
        }

        // Falls WAHA eine LID-chatId zurückgibt, diese verwenden
        if (!empty($chkResult['chatId'])) {
            $chatId = $chkResult['chatId'];
        }

        $text = $this->FillTemplate($this->ReadPropertyString('WhatsAppTemplate'), $guest, $event);

        if ($isReminder) {
            $text = "⏰ Erinnerung: Wir warten noch auf deine Rückmeldung!\n\n" . $text;
        }

        $payload = json_encode([
            'session' => $session,
            'chatId'  => $chatId,
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

    private function FillTemplate(string $template, array $guest, array $event, bool $isHtml = false): string
    {
        $formUrl  = $event['formUrl'] ?? '';
        $entryEmail = trim($this->ReadPropertyString('RSVPEmailEntry'));
        $entryName  = trim($this->ReadPropertyString('RSVPNameEntry'));
        
        $rsvpLink = $formUrl;
        $params   = [];

        if (!empty($entryEmail) && !empty($guest['email'])) {
            $key = str_starts_with($entryEmail, 'entry.') ? $entryEmail : 'entry.' . $entryEmail;
            $params[] = $key . '=' . urlencode($guest['email']);
        }
        
        if (!empty($entryName) && !empty($guest['name'])) {
            $key = str_starts_with($entryName, 'entry.') ? $entryName : 'entry.' . $entryName;
            $params[] = $key . '=' . urlencode($guest['name']);
        }
        
        if (!empty($params)) {
            $rsvpLink .= "?usp=pp_url&" . implode('&', $params);
        }
        
        $rsvpDisplay = $isHtml ? '<a href="' . $rsvpLink . '">' . htmlspecialchars($rsvpLink) . '</a>' : $rsvpLink;

        $replace = [
            '{GuestName}'     => $guest['name'] ?? '',
            '{RSVPLink}'      => $rsvpDisplay,
        ];

        return str_replace(array_keys($replace), array_values($replace), $template);
    }

    private function ExtractFormId(string $formUrl): string
    {
        // 1FAIp... Responder URL ist FALSCH fuer die API
        if (str_contains($formUrl, '/forms/d/e/1FAIp')) {
            return '';
        }

        if (preg_match('/\/forms\/d\/([a-zA-Z0-9_-]+)/', $formUrl, $m)) {
            if ($m[1] === 'e') {
                return '';
            }
            return $m[1];
        }

        // Fallback: Falls der User direkt die ID eingetragen hat
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $formUrl)) {
            return $formUrl;
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
        $eventData = $this->GetEventData();
        $guests    = $eventData['guests'] ?? [];
        $event     = $eventData['event'] ?? [];

        $total     = count($guests);
        $confirmed = count(array_filter($guests, fn($g) => $g['status'] === 'confirmed'));
        $declined  = count(array_filter($guests, fn($g) => $g['status'] === 'declined'));
        $pending   = count(array_filter($guests, fn($g) => $g['status'] === 'pending'));

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
            
            $detailsText = !empty($g['details']) ? htmlspecialchars($g['details']) : '';
            $detailsHtml = $detailsText ? "<br><span style=\"font-size:0.85em;color:#777;\">Zusatzinfos: {$detailsText}</span>" : '';

            $rows .= "<tr style=\"background:{$statusColor};border-bottom:1px solid #eee;\">
                <td style=\"padding:12px;\">{$statusIcon} <strong style=\"font-size:1.05em;\">{$name}</strong>{$detailsHtml}</td>
                <td style=\"padding:12px;\">{$contact}</td>
                <td style=\"padding:12px;\">{$channelIcon}</td>
                <td style=\"padding:12px;\">{$statusText}</td>
                <td style=\"padding:12px;font-size:0.85em;color:#555;\">Einladung: {$invitedAt}<br>Antwort: {$respondedAt}</td>
            </tr>";
        }

        return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:900px;">
    <table style="width:100%;border-collapse:collapse;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
        <thead>
            <tr style="background:#f1f1f1;text-align:left;">
                <th style="padding:10px 12px;">Gast</th>
                <th style="padding:10px 12px;">Kontakt</th>
                <th style="padding:10px 12px;">Kanal</th>
                <th style="padding:10px 12px;">Status</th>
                <th style="padding:10px 12px;">Zeitstempel</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
HTML;
    }
}
