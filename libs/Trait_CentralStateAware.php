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
        protected function SubscribeToCentralStates(array $stateNames): void
        {
            // Unregister old messages
            $oldMapStr = $this->GetBuffer('CSA_VarMap');
            if ($oldMapStr !== '') {
                $oldMap = json_decode($oldMapStr, true);
                if (is_array($oldMap)) {
                    foreach ($oldMap as $varID => $ident) {
                        $this->UnregisterMessage((int)$varID, 10603); // VM_UPDATE = 10603
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
                    $this->RegisterMessage($varID, 10603); // VM_UPDATE
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
                    $this->MaintainVariable($mirrorIdent, IPS_GetName($varID), 1, '', -100, true);
                    
                    $varObj = IPS_GetVariable($varID);
                    $profile = $varObj['VariableCustomProfile'] !== '' ? $varObj['VariableCustomProfile'] : $varObj['VariableProfile'];
                    IPS_SetVariableCustomProfile($this->GetIDForIdent($mirrorIdent), $profile);
                    IPS_SetIcon($this->GetIDForIdent($mirrorIdent), IPS_GetObject($varID)['ObjectIcon']);
                    
                    // Setze den initialen Wert
                    $this->SetValue($mirrorIdent, $value);
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
            if ($Message === 10603) { // VM_UPDATE
                $mapStr = $this->GetBuffer('CSA_VarMap');
                if ($mapStr !== '') {
                    $map = json_decode($mapStr, true);
                    if (is_array($map) && isset($map[$SenderID])) {
                        $ident = $map[$SenderID];
                        $newValue = $Data[0];
                        
                        $this->SetBuffer('CSA_' . $ident, serialize($newValue));
                        $this->SetValue('CSA_State_' . $ident, $newValue);
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
        abstract private function OnCentralStateChanged(string $stateName, mixed $newValue): void;
    }
}
