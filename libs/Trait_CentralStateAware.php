<?php

declare(strict_types=1);

if (!trait_exists('CentralStateAware_Trait')) {
    /**
     * Trait CentralStateAware_Trait
     * 
     * Allows any IP-Symcon module to subscribe to central state variables managed by the SmartHomeControl module.
     * 
     * Usage pattern:
     * 1. Use the trait in your module class.
     * 2. In ApplyChanges(), call $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode', ...]).
     * 3. In MessageSink(), call $this->HandleCentralStateMessage($SenderID, $Message, $Data) and return if true.
     * 4. Implement OnCentralStateChanged(string $stateName, mixed $newValue).
     * 
     * @author Florian Graßinger
     * @url https://github.com/pinkerunicorn/
     */
    trait CentralStateAware_Trait
    {
        /**
         * Discovers SmartHomeControl, unregisters old subscriptions, registers new ones,
         * and caches the initial values.
         * 
         * @param array $stateNames Array of Idents (e.g., 'PresenceMode', 'ActivityMode')
         */
        protected function SubscribeToCentralStates(array $stateNames, bool $createMirrors = false): void
        {
            // Unregister old messages
            $oldMapStr = $this->GetBuffer('CSA_VarMap');
            if ($oldMapStr !== '') {
                $oldMap = json_decode($oldMapStr, true);
                if (is_array($oldMap)) {
                    foreach ($oldMap as $varID => $ident) {
                        $this->UnregisterMessage((int)$varID, VM_UPDATE);
                    }
                }
            }

            $instances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
            if (count($instances) === 0) {
                // SmartHomeControl not found
                return;
            }

            $shcID = $instances[0];
            $varMap = [];

            foreach ($stateNames as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $shcID);
                if ($varID !== false && $varID > 0) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                    $varMap[$varID] = $ident;
                    
                    // Cache current value
                    $value = GetValue($varID);
                    $this->SetBuffer('CSA_' . $ident, serialize($value));
                    
                    // Lösche eventuell alte Links aus der Vorversion
                    $linkIdent = 'CSA_Link_' . $ident;
                    $linkID = @IPS_GetObjectIDByIdent($linkIdent, $this->InstanceID);
                    if ($linkID !== false) {
                        IPS_DeleteLink($linkID);
                    }
                    
                    // Erstelle eine ECHTE Variable im Modul als Mirror ("Beweis" dass das Modul es verstanden hat)
                    $mirrorIdent = 'CSA_State_' . $ident;
                    if ($createMirrors) {
                        $varObj = IPS_GetVariable($varID);
                        
                        $baseProfile = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
                        
                        // Symcon 8: Custom Presentation explicitly for central states
                        if ($ident === 'PresenceMode') {
                            $baseProfile['ICON'] = 'House';
                            $baseProfile['INTERVALS_ACTIVE'] = true;
                            $baseProfile['INTERVALS'] = json_encode([
                                [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Zuhause', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'House', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
                                [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Kurz weg', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'person-running', 'ColorActive' => true, 'ColorValue' => 0xFFAA00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
                                [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Urlaub', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Suitcase', 'ColorActive' => true, 'ColorValue' => 0xFF4400, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
                            ]);
                        } elseif ($ident === 'ActivityMode') {
                            $baseProfile['ICON'] = 'Sun';
                            $baseProfile['INTERVALS_ACTIVE'] = true;
                            $baseProfile['INTERVALS'] = json_encode([
                                [ 'IntervalMinValue' => 0, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Normal', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'sun', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
                                [ 'IntervalMinValue' => 1, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Kino', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'tv', 'ColorActive' => true, 'ColorValue' => 0x8800FF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
                                [ 'IntervalMinValue' => 2, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Schlafen', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'moon', 'ColorActive' => true, 'ColorValue' => 0x0000FF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ],
                                [ 'IntervalMinValue' => 3, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'Party', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'speaker', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF ]
                            ]);
                        }

                        if ($varObj['VariableType'] === 0) {
                            $this->RegisterVariableBoolean($mirrorIdent, IPS_GetName($varID), $baseProfile, -100);
                        } elseif ($varObj['VariableType'] === 1) {
                            $this->RegisterVariableInteger($mirrorIdent, IPS_GetName($varID), $baseProfile, -100);
                        } elseif ($varObj['VariableType'] === 2) {
                            $this->RegisterVariableFloat($mirrorIdent, IPS_GetName($varID), $baseProfile, -100);
                        } else {
                            $this->RegisterVariableString($mirrorIdent, IPS_GetName($varID), $baseProfile, -100);
                        }
                        
                        $profile = $varObj['VariableCustomProfile'] !== '' ? $varObj['VariableCustomProfile'] : $varObj['VariableProfile'];
                        if ($profile !== '') {
                            IPS_SetVariableCustomProfile($this->GetIDForIdent($mirrorIdent), $profile);
                        }
                        IPS_SetIcon($this->GetIDForIdent($mirrorIdent), IPS_GetObject($varID)['ObjectIcon']);
                        
                        // Setze den initialen Wert
                        $this->SetValue($mirrorIdent, $value);
                    } else {
                        // Lösche den Mirror, falls vorhanden
                        $mirrorID = @$this->GetIDForIdent($mirrorIdent);
                        if ($mirrorID !== false) {
                            $this->UnregisterVariable($mirrorIdent);
                        }
                    }
                }
            }

            $this->SetBuffer('CSA_VarMap', json_encode($varMap));
        }

        /**
         * Handles incoming messages for central state variables.
         * 
         * @param int $SenderID
         * @param int $Message
         * @param array $Data
         * @return bool True if message was from a subscribed central state variable
         */
        protected function HandleCentralStateMessage(int $SenderID, int $Message, array $Data): bool
        {
            if ($Message === VM_UPDATE) {
                $mapStr = $this->GetBuffer('CSA_VarMap');
                if ($mapStr !== '') {
                    $map = json_decode($mapStr, true);
                    if (is_array($map) && isset($map[$SenderID])) {
                        $ident = $map[$SenderID];
                        $newValue = $Data[0];
                        
                        $this->SetBuffer('CSA_' . $ident, serialize($newValue));
                        if (@$this->GetIDForIdent('CSA_State_' . $ident) !== false) {
                            $this->SetValue('CSA_State_' . $ident, $newValue);
                        }
                        $this->OnCentralStateChanged($ident, $newValue);
                        return true;
                    }
                }
            }
            return false;
        }

        /**
         * @return int Cached PresenceMode value
         */
        public function GetPresenceMode(): int
        {
            $val = $this->GetBuffer('CSA_PresenceMode');
            return $val !== '' ? (int)unserialize($val) : 0;
        }

        /**
         * @return int Cached ActivityMode value
         */
        public function GetActivityMode(): int
        {
            $val = $this->GetBuffer('CSA_ActivityMode');
            return $val !== '' ? (int)unserialize($val) : 0;
        }

        /**
         * Generic getter for any cached central state value.
         * 
         * @param string $ident The state ident (e.g., 'FireplaceActive', 'IrrigationActive')
         * @return mixed The cached value, or null if not subscribed
         */
        public function GetCentralState(string $ident): mixed
        {
            $val = $this->GetBuffer('CSA_' . $ident);
            return $val !== '' ? unserialize($val) : null;
        }

        public function IsHome(): bool
        {
            return $this->GetPresenceMode() === 0;
        }

        public function IsAway(): bool
        {
            return $this->GetPresenceMode() === 1;
        }

        public function IsVacation(): bool
        {
            return $this->GetPresenceMode() === 2;
        }

        public function IsNormal(): bool
        {
            return $this->GetActivityMode() === 0;
        }

        public function IsCinema(): bool
        {
            return $this->GetActivityMode() === 1;
        }

        public function IsSleeping(): bool
        {
            return $this->GetActivityMode() === 2;
        }

        public function IsParty(): bool
        {
            return $this->GetActivityMode() === 3;
        }

        /**
         * Callback triggered when a subscribed central state changes.
         * 
         * @param string $stateName Ident of the changed central state
         * @param mixed $newValue New value of the state
         */
        abstract protected function OnCentralStateChanged(string $stateName, mixed $newValue): void;
    }
}
