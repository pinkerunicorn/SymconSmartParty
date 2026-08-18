<?php

declare(strict_types=1);

/**
 * SmartLog Trait — Einbinden in jedes Modul für zentrales Logging.
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_SmartLog.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use SmartLog_Trait;
 *       ...
 *       $this->SLog('INFO', 'Etwas ist passiert');
 *   }
 *
 * Der Trait findet die SmartLog-Instanz automatisch per ModuleID.
 * Falls keine SmartLog-Instanz existiert, wird auf IPS_LogMessage() zurückgefallen.
 */
if (!trait_exists('SmartLog_Trait')) {
trait SmartLog_Trait
{
    /**
     * Sendet eine Logmeldung an das zentrale SmartLog-Modul.
     *
     * @param string $level   DEBUG, INFO, WARNING, ERROR
     * @param string $message Kurze Logmeldung
     * @param string $details Optionale Details
     */
    private function SLog(string $level, string $message, string $details = '', string $trigger = ''): void
    {
        // Modulnamen aus dem Klassennamen ableiten
        $source = IPS_GetName($this->InstanceID);

        $slogInstances = @IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
        if (is_array($slogInstances) && count($slogInstances) > 0) {
            if (function_exists('SLOG_Log')) {
                SLOG_Log($slogInstances[0], $level, $source, $message, $details, $trigger);
            }
        } else {
            IPS_LogMessage($source, $message);
        }
    }

    private function SLogDebug(string $message, string $details = '', string $trigger = ''): void
    {
        $this->SLog('DEBUG', $message, $details, $trigger);
    }

    private function SLogInfo(string $message, string $details = '', string $trigger = ''): void
    {
        $this->SLog('INFO', $message, $details, $trigger);
    }

    private function SLogWarning(string $message, string $details = '', string $trigger = ''): void
    {
        $this->SLog('WARNING', $message, $details, $trigger);
    }

    private function SLogError(string $message, string $details = '', string $trigger = ''): void
    {
        $this->SLog('ERROR', $message, $details, $trigger);
    }
}
}
