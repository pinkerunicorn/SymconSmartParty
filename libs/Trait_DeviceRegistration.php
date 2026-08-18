<?php

declare(strict_types=1);

if (!trait_exists('DeviceRegistration_Trait')) {
    /**
     * Trait DeviceRegistration_Trait
     *
     * Enables IP-Symcon modules to automatically register with the Device Registry.
     */
    trait DeviceRegistration_Trait
    {
        /**
         * Registers the module with the Device Registry.
         *
         * Automatically detects the DeviceAvailable variable if DeviceAvailability_Trait is used.
         * Additional variables can optionally be passed for richer metadata.
         *
         * @param string $deviceType The type of the device (e.g. 'DevicesSwitch', 'DevicesContactSensor').
         * @param array $variables Optional additional variables to register.
         * @return void
         */
        private function DR_Register(string $deviceType, array $variables = []): void
        {
            $registryIDs = @IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
            if ($registryIDs === false || count($registryIDs) === 0) {
                return;
            }
            $registryID = $registryIDs[0];

            // Auto-detect DeviceAvailable if not explicitly provided
            if (!isset($variables['Reachable_VarID'])) {
                $availableVarID = @$this->GetIDForIdent('DeviceAvailable');
                if ($availableVarID !== false && $availableVarID > 0) {
                    $variables['Reachable_VarID'] = $availableVarID;
                }
            }

            $location = @IPS_GetLocation($this->InstanceID);
            if ($location === false) {
                $location = '';
            }

            $room = '';
            $floor = '';
            if (function_exists('SDR_ResolveLocation')) {
                $resolved = @SDR_ResolveLocation($registryID, $location);
                if (is_string($resolved)) {
                    $resolved = json_decode($resolved, true);
                }
                if (is_array($resolved)) {
                    $room = $resolved['room'] ?? ($resolved['Room'] ?? '');
                    $floor = $resolved['floor'] ?? ($resolved['Floor'] ?? '');
                }
            }

            if (function_exists('SDR_AutoRegister')) {
                $instance = @IPS_GetInstance($this->InstanceID);
                $moduleGUID = (is_array($instance) && isset($instance['ModuleInfo']['ModuleID'])) ? $instance['ModuleInfo']['ModuleID'] : '';
                
                $name = @IPS_GetName($this->InstanceID);
                if ($name === false) {
                    $name = '';
                }

                $payload = [
                    'instanceID' => $this->InstanceID,
                    'moduleGUID' => $moduleGUID,
                    'type'       => $deviceType,
                    'name'       => $name,
                    'location'   => $location,
                    'room'       => $room,
                    'floor'      => $floor,
                    'variables'  => $variables,
                    'source'     => 'trait'
                ];

                @SDR_AutoRegister($registryID, json_encode($payload));
            }
        }

        /**
         * Removes the registration from the Device Registry.
         *
         * @return void
         */
        private function DR_Unregister(): void
        {
            $registryIDs = @IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
            if ($registryIDs === false || count($registryIDs) === 0) {
                return;
            }
            $registryID = $registryIDs[0];

            if (function_exists('SDR_AutoUnregister')) {
                @SDR_AutoUnregister($registryID, $this->InstanceID);
            }
        }
    }
}
