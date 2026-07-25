<?php

declare(strict_types=1);

class SmartPartyGateway extends IPSModuleStrict
{
    // Google OAuth2 / API Endpoints
    private const GOOGLE_AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_PEOPLE_BASE = 'https://people.googleapis.com/v1';
    private const GOOGLE_FORMS_BASE  = 'https://forms.googleapis.com/v1';

    private const OAUTH_SCOPES = [
        'https://www.googleapis.com/auth/contacts.readonly',
        'https://www.googleapis.com/auth/forms.responses.readonly',
    ];

    // =========================================================================
    // Lifecycle
    // =========================================================================

    public function Create(): void
    {
        parent::Create();

        // Google OAuth2
        $this->RegisterPropertyString('GoogleClientID', '');
        $this->RegisterPropertyString('GoogleClientSecret', '');

        // External Symcon URL for OAuth redirect (e.g. https://xxx.ipmagic.de)
        $this->RegisterPropertyString('SymconExternalURL', '');

        // Google Contacts Labels
        $this->RegisterPropertyString('LabelMail', 'PartyMail');
        $this->RegisterPropertyString('LabelWhatsApp', 'PartyWhatsApp');
        $this->RegisterPropertyString('LabelBoth', 'PartyBoth');

        // Persistent OAuth token storage
        $this->RegisterAttributeString('GoogleRefreshToken', '');
        $this->RegisterAttributeString('GoogleAccessToken', '');
        $this->RegisterAttributeInteger('GoogleTokenExpiry', 0);

        // Status variable
        $this->RegisterVariableString('AuthStatus', 'Google Auth Status', '', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterHook('/hook/SmartPartyGateway');
        $this->UpdateAuthStatusVariable();
    }

    // =========================================================================
    // Data Flow — ForwardData (called by child SmartPartyManager)
    // =========================================================================

    public function ForwardData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $this->SendDebug('ForwardData', 'DataID: ' . ($data['DataID'] ?? ''), 0);

        if (($data['DataID'] ?? '') !== '{F5569D5F-A5EC-4FDA-B728-44A96B393DF3}') {
            return json_encode(['error' => 'Unknown DataID']);
        }

        $buffer   = json_decode($data['Buffer'], true);
        $function = $buffer['Function'] ?? '';
        $params   = $buffer['Parameters'] ?? [];

        $result = match ($function) {
            'FetchGuests'      => $this->FetchGuests(),
            'GetRSVPResponses' => $this->GetRSVPResponses($params['formId'] ?? ''),
            default            => ['error' => 'Unknown function: ' . $function],
        };

        return json_encode($result);
    }

    // =========================================================================
    // Configuration Form
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        $isConnected = !empty($this->ReadAttributeString('GoogleRefreshToken'));
        $connectLabel = $isConnected
            ? '✅ Google ist autorisiert'
            : '❌ Noch nicht autorisiert';

        return json_encode([
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => '── Google OAuth2 ──────────────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'GoogleClientID',
                    'caption' => 'Google Client-ID',
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'GoogleClientSecret',
                    'caption' => 'Google Client-Secret',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'SymconExternalURL',
                    'caption' => 'Symcon externe URL (z.B. https://xxx.ipmagic.de)',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Status: ' . $connectLabel,
                ],
                [
                    'type'    => 'Label',
                    'caption' => '── Google Contacts Labels ─────────────────────────',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'LabelMail',
                    'caption' => 'Label für E-Mail Einladungen',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'LabelWhatsApp',
                    'caption' => 'Label für WhatsApp Einladungen',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'LabelBoth',
                    'caption' => 'Label für E-Mail + WhatsApp',
                ],
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => '🔐 Schritt 1: Autorisierungs-URL anzeigen',
                    'onClick' => 'SPG_StartAuth($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🔄 Verbindung testen',
                    'onClick' => 'SPG_TestConnection($id);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🗑️ Autorisierung widerrufen',
                    'onClick' => 'SPG_RevokeAuth($id);',
                ],
            ],
        ]);
    }

    // =========================================================================
    // Public Actions (called from Config Form)
    // =========================================================================

    public function StartAuth(): void
    {
        $clientId = $this->ReadPropertyString('GoogleClientID');
        $externalUrl = trim($this->ReadPropertyString('SymconExternalURL'), '/');

        if (empty($clientId)) {
            echo 'Fehler: Bitte zuerst die Google Client-ID eintragen und speichern.';
            return;
        }
        if (empty($externalUrl)) {
            echo 'Fehler: Bitte die externe Symcon-URL eintragen (z.B. https://xxx.ipmagic.de).';
            return;
        }

        $redirectUri = $externalUrl . '/hook/SmartPartyGateway';

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', self::OAUTH_SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        $authUrl = self::GOOGLE_AUTH_URL . '?' . $params;

        echo "Öffne folgenden Link im Browser und melde dich mit deinem Google-Konto an:\n\n" . $authUrl . "\n\n";
        echo "Nach der Autorisierung wirst du automatisch zurückgeleitet und der Status aktualisiert sich.";
    }

    public function RevokeAuth(): void
    {
        $this->WriteAttributeString('GoogleRefreshToken', '');
        $this->WriteAttributeString('GoogleAccessToken', '');
        $this->WriteAttributeInteger('GoogleTokenExpiry', 0);
        $this->UpdateAuthStatusVariable();
        echo 'Autorisierung wurde widerrufen.';
    }

    public function TestConnection(): void
    {
        $token = $this->GetAccessToken();
        if (empty($token)) {
            echo '❌ Kein gültiges Access Token. Bitte Autorisierung starten.';
            return;
        }

        $url = self::GOOGLE_PEOPLE_BASE . '/contactGroups?pageSize=1';
        $result = $this->GoogleApiGet($url, $token);

        if ($result !== false) {
            echo '✅ Verbindung erfolgreich! Google API ist erreichbar.';
        } else {
            echo '❌ Verbindung fehlgeschlagen. Prüfe Client-ID, Secret und Autorisierung.';
        }
    }

    // =========================================================================
    // Webhook — OAuth2 Callback
    // =========================================================================

    protected function ProcessHookData(): void
    {
        if (isset($_GET['code'])) {
            $this->HandleOAuthCallback($_GET['code']);
            return;
        }

        if (isset($_GET['error'])) {
            echo '<h2>❌ Autorisierung fehlgeschlagen</h2>';
            echo '<p>' . htmlspecialchars($_GET['error']) . '</p>';
            return;
        }

        echo 'SmartPartyGateway Webhook aktiv.';
    }

    private function HandleOAuthCallback(string $code): void
    {
        $clientId     = $this->ReadPropertyString('GoogleClientID');
        $clientSecret = $this->ReadPropertyString('GoogleClientSecret');
        $externalUrl  = trim($this->ReadPropertyString('SymconExternalURL'), '/');
        $redirectUri  = $externalUrl . '/hook/SmartPartyGateway';

        $postData = http_build_query([
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $ch = curl_init(self::GOOGLE_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            $this->SendDebug('OAuth', 'Token exchange failed HTTP ' . $httpCode . ': ' . $response, 0);
            echo '<h2>❌ Token-Austausch fehlgeschlagen</h2>';
            echo '<p><strong>Details (HTTP ' . $httpCode . '):</strong> ' . htmlspecialchars((string)$response) . '</p>';
            echo '<p>Bitte prüfe Client-ID, Client-Secret und die Autorisierte Weiterleitungs-URI in der Google Cloud Console.</p>';
            return;
        }

        $tokenData = json_decode($response, true);

        if (!isset($tokenData['access_token'])) {
            $this->SendDebug('OAuth', 'No access token in response: ' . $response, 0);
            echo '<h2>❌ Kein Access Token erhalten</h2>';
            return;
        }

        if (!isset($tokenData['refresh_token'])) {
            echo '<h2>❌ Kein Refresh Token erhalten</h2>';
            echo '<p>Bitte widerrufe die App unter <a href="https://myaccount.google.com/permissions">myaccount.google.com/permissions</a> und starte die Autorisierung neu.</p>';
            return;
        }

        // Speichere Tokens persistent
        $this->WriteAttributeString('GoogleRefreshToken', $tokenData['refresh_token']);
        $this->WriteAttributeString('GoogleAccessToken', $tokenData['access_token']);
        $this->WriteAttributeInteger('GoogleTokenExpiry', time() + ($tokenData['expires_in'] ?? 3600) - 60);
        $this->UpdateAuthStatusVariable();

        $this->SendDebug('OAuth', 'Authorization successful', 0);

        echo '<h2>✅ Erfolgreich mit Google verbunden!</h2>';
        echo '<p>Du kannst dieses Fenster schließen und im IP-Symcon SmartPartyManager loslegen.</p>';
    }

    // =========================================================================
    // Public API — aufgerufen vom SmartPartyManager
    // =========================================================================

    /**
     * Lädt alle Gäste aus den drei Party-Labels in Google Contacts.
     * Gibt Array zurück: [{resourceName, name, email, phone, channel}, ...]
     */
    public function FetchGuests(): array
    {
        $token = $this->GetAccessToken();
        if (empty($token)) {
            $this->SendDebug('FetchGuests', 'No access token', 0);
            return [];
        }

        $labelMail      = $this->ReadPropertyString('LabelMail');
        $labelWhatsApp  = $this->ReadPropertyString('LabelWhatsApp');
        $labelBoth      = $this->ReadPropertyString('LabelBoth');

        // Schritt 1: Alle Kontaktgruppen abrufen
        $url = self::GOOGLE_PEOPLE_BASE . '/contactGroups?pageSize=200';
        $raw = $this->GoogleApiGet($url, $token);
        if ($raw === false) {
            return [];
        }

        $groupsData  = json_decode($raw, true);
        $targetGroups = []; // resourceName => channel

        foreach ($groupsData['contactGroups'] ?? [] as $group) {
            $name = $group['name'] ?? '';
            $channel = match ($name) {
                $labelMail     => 'email',
                $labelWhatsApp => 'whatsapp',
                $labelBoth     => 'both',
                default        => '',
            };
            if ($channel !== '') {
                $targetGroups[$group['resourceName']] = $channel;
            }
        }

        if (empty($targetGroups)) {
            $this->SendDebug('FetchGuests', 'Labels not found: ' . implode(', ', [$labelMail, $labelWhatsApp, $labelBoth]), 0);
            return [];
        }

        // Schritt 2: Mitglieder jeder Gruppe laden
        $guestMap = []; // resourceName => channel

        foreach ($targetGroups as $groupResourceName => $channel) {
            $url = self::GOOGLE_PEOPLE_BASE . '/' . $groupResourceName . '?maxMembers=200';
            $raw = $this->GoogleApiGet($url, $token);
            if ($raw === false) {
                continue;
            }

            $groupInfo = json_decode($raw, true);
            foreach ($groupInfo['memberResourceNames'] ?? [] as $memberRN) {
                if (!isset($guestMap[$memberRN])) {
                    $guestMap[$memberRN] = $channel;
                } else {
                    // In mehreren Labels → immer 'both'
                    $guestMap[$memberRN] = 'both';
                }
            }
        }

        if (empty($guestMap)) {
            $this->SendDebug('FetchGuests', 'No members found in labels', 0);
            return [];
        }

        // Schritt 3: Kontaktdetails in Batches laden (max 50 pro Request)
        $chunks = array_chunk(array_keys($guestMap), 50);
        $guests = [];

        foreach ($chunks as $chunk) {
            $paramStr = '';
            foreach ($chunk as $rn) {
                $paramStr .= 'resourceNames=' . urlencode($rn) . '&';
            }
            $paramStr .= 'personFields=names%2CemailAddresses%2CphoneNumbers';

            $url = self::GOOGLE_PEOPLE_BASE . '/people:batchGet?' . $paramStr;
            $raw = $this->GoogleApiGet($url, $token);
            if ($raw === false) {
                continue;
            }

            $batch = json_decode($raw, true);

            foreach ($batch['responses'] ?? [] as $response) {
                $person = $response['person'] ?? null;
                if ($person === null) {
                    continue;
                }

                $resourceName = $person['resourceName'] ?? '';
                $name         = $person['names'][0]['displayName'] ?? 'Unbekannt';
                $email        = $person['emailAddresses'][0]['value'] ?? '';
                $phone        = $this->NormalizePhone($person['phoneNumbers'][0]['value'] ?? '');
                $channel      = $guestMap[$resourceName] ?? 'email';

                $guests[] = [
                    'resourceName' => $resourceName,
                    'name'         => $name,
                    'email'        => $email,
                    'phone'        => $phone,
                    'channel'      => $channel,
                    'status'       => 'pending',
                    'invitedVia'   => [],
                    'invitedAt'    => null,
                    'respondedAt'  => null,
                ];
            }
        }

        $this->SendDebug('FetchGuests', 'Loaded ' . count($guests) . ' guests', 0);
        return $guests;
    }

    /**
     * Liest alle Antworten aus einem Google Form.
     * Gibt Array der Rohantworten zurück.
     */
    public function GetRSVPResponses(string $formId): array
    {
        $token = $this->GetAccessToken();
        if (empty($token)) {
            return [];
        }

        $url = self::GOOGLE_FORMS_BASE . '/forms/' . $formId . '/responses';
        $raw = $this->GoogleApiGet($url, $token);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        return $data['responses'] ?? [];
    }

    // =========================================================================
    // Private Helper
    // =========================================================================

    private function GetAccessToken(): string
    {
        $expiry      = $this->ReadAttributeInteger('GoogleTokenExpiry');
        $accessToken = $this->ReadAttributeString('GoogleAccessToken');

        // Gecachtes Token noch gültig?
        if (!empty($accessToken) && time() < $expiry) {
            return $accessToken;
        }

        // Token via Refresh Token erneuern
        $refreshToken = $this->ReadAttributeString('GoogleRefreshToken');
        if (empty($refreshToken)) {
            $this->SendDebug('GetAccessToken', 'No refresh token', 0);
            return '';
        }

        $clientId     = $this->ReadPropertyString('GoogleClientID');
        $clientSecret = $this->ReadPropertyString('GoogleClientSecret');

        $postData = http_build_query([
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'refresh_token',
        ]);

        $ch = curl_init(self::GOOGLE_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            $this->SendDebug('GetAccessToken', 'Refresh failed HTTP ' . $httpCode, 0);
            $this->SetValue('AuthStatus', 'Token-Refresh fehlgeschlagen - Bitte neu autorisieren');
            return '';
        }

        $tokenData = json_decode($response, true);
        $newToken  = $tokenData['access_token'] ?? '';
        $expiresIn = (int) ($tokenData['expires_in'] ?? 3600);

        $this->WriteAttributeString('GoogleAccessToken', $newToken);
        $this->WriteAttributeInteger('GoogleTokenExpiry', time() + $expiresIn - 60);

        return $newToken;
    }

    private function GoogleApiGet(string $url, string $token): string|false
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->SendDebug('GoogleApiGet', 'HTTP ' . $httpCode . ' — URL: ' . $url . ' — Response: ' . $response, 0);
            return false;
        }

        return $response;
    }

    /**
     * Telefonnummer normalisieren für WAHA: +49 170 1234567 → 491701234567
     */
    private function NormalizePhone(string $phone): string
    {
        // Alles außer Ziffern entfernen
        $phone = preg_replace('/[^\d]/', '', $phone);
        // Führende Nullen entfernen (z.B. 0170... → 49170...)
        if (str_starts_with($phone, '0')) {
            $phone = '49' . substr($phone, 1);
        }
        return $phone;
    }

    private function UpdateAuthStatusVariable(): void
    {
        $refreshToken = $this->ReadAttributeString('GoogleRefreshToken');
        if (!empty($refreshToken)) {
            $this->SetValue('AuthStatus', 'Verbunden mit Google');
        } else {
            $this->SetValue('AuthStatus', 'Nicht autorisiert - Bitte Autorisierung starten');
        }
    }

    protected function RegisterHook(string $HookPath): bool
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $HookPath) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return true;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $HookPath, 'TargetID' => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
        return true;
    }
}
