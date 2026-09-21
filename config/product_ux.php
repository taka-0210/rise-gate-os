<?php

return [
    /*
    | Product Organization Admission remains disabled until the production
    | inventory and production-equivalent concurrency release gates pass.
    */
    'organization_admission_enabled' => env('PRODUCT_ORGANIZATION_ADMISSION_ENABLED', false),
    'classification_version' => 'pux-a-v1',
    'operation_fixture_seeding_enabled' => env('PRODUCT_UX_OPERATION_FIXTURE_SEED_ENABLED', false),
];
