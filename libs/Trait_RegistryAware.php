<?php

declare(strict_types=1);

if (!trait_exists('RegistryAware_Trait')) {
    trait RegistryAware_Trait
    {
        private function DR_GetControllerID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }

        private function DR_GetNotifierID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }

        private function DR_GetLogID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }
    }
}