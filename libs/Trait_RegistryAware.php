<?php

declare(strict_types=1);

/**
 * RegistryAware Trait — Zentraler Service-Locator für das Smart Home Ökosystem.
 *
 * Bietet Multi-House-fähige Discovery für:
 * - DeviceRegistry (pro Haus, über RegistryID Property)
 * - SmartController (pro Haus, über SDR_GetControllerID)
 * - SmartNotifier (global, GUID-Singleton)
 * - SmartLog (global, GUID-Singleton)
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_RegistryAware.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use RegistryAware_Trait;
 *       ...
 *       $regID = $this->DR_GetRegistryID();
 *       $ctrlID = $this->DR_GetControllerID();
 *       $notifyID = $this->DR_GetNotifierID();
 *   }
 *
 * Voraussetzung: Das Modul MUSS RegisterPropertyInteger('RegistryID', 0) in Create() aufrufen,
 * damit der Fallback bei mehreren Registries funktioniert.
 * Bei genau einer Registry im System greift der automatische Fallback.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
if (!trait_exists('RegistryAware_Trait')) {
    trait RegistryAware_Trait
    {
        /**
         * Gibt die konfigurierte RegistryID zurück.
         * Fallback: Wenn nicht gesetzt und genau 1 Registry existiert → diese verwenden.
         *
         * @return int InstanceID der DeviceRegistry, oder 0 wenn nicht gefunden
         */
        private function DR_GetRegistryID(): int
        {
            // 1. Explizit konfigurierte Property
            try {
                $configured = $this->ReadPropertyInteger('RegistryID');
                if ($configured > 0 && @IPS_InstanceExists($configured)) {
                    return $configured;
                }
            } catch (\Throwable $e) {
                // Property existiert bei diesem Modul nicht
            }

            // 2. Fallback: Einzige Registry im System (1-Haus-Betrieb)
            $ids = @IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
            if (is_array($ids) && count($ids) === 1) {
                return $ids[0];
            }

            return 0;
        }

        /**
         * Findet den SmartController dieses Hauses über die DeviceRegistry.
         * Die Registry speichert die ControllerID als Property.
         *
         * @return int InstanceID des SmartControllers, oder 0 wenn nicht gefunden
         */
        private function DR_GetControllerID(): int
        {
            $regID = $this->DR_GetRegistryID();
            if ($regID === 0) {
                // Kein Registry gefunden → Fallback auf GUID-Lookup
                $ids = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
                return (is_array($ids) && count($ids) === 1) ? $ids[0] : 0;
            }

            if (function_exists('SDR_GetControllerID')) {
                $ctrlID = @SDR_GetControllerID($regID);
                if (is_int($ctrlID) && $ctrlID > 0) {
                    return $ctrlID;
                }
            }

            // Fallback: Registry hat noch keine ControllerID konfiguriert
            $ids = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
            return (is_array($ids) && count($ids) === 1) ? $ids[0] : 0;
        }

        /**
         * Findet den globalen SmartNotifier per GUID (Singleton).
         *
         * @return int InstanceID des SmartNotifiers, oder 0 wenn nicht gefunden
         */
        private function DR_GetNotifierID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }

        /**
         * Findet das globale SmartLog per GUID (Singleton).
         *
         * @return int InstanceID des SmartLog, oder 0 wenn nicht gefunden
         */
        private function DR_GetLogID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }
    }
}
