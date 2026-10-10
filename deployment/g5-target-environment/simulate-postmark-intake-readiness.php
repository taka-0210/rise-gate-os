<?php

// Contract-state simulation only: no token parameter, network, process or filesystem mutation.
return static function (array $requiredChecks, array $observations): array {
    foreach ($requiredChecks as $check) {
        if (($observations[$check] ?? null) !== true) {
            return ['result' => 'STOP', 'safe_error_code' => 'READINESS_CHECK_FAILED', 'publish_allowed' => false];
        }
    }

    return ['result' => 'SIMULATION_PASS', 'safe_error_code' => null, 'publish_allowed' => false];
};
