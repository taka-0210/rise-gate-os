<?php

return [
    /*
    | Both switches are fail-closed. Enabling cutover never enables Phase E;
    | finalization requires its own later human approval and separate switch.
    */
    'cutover_enabled' => env('ACCOUNT_SEPARATION_CUTOVER_ENABLED', false),
    'finalize_enabled' => env('ACCOUNT_SEPARATION_FINALIZE_ENABLED', false),
];
