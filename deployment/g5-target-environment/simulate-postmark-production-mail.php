<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        fwrite(STDERR, "G5_POSTMARK_PRODUCTION_MAIL_SIMULATION=STOP\nassertion={$message}\n");
        exit(1);
    }
};

$evaluate = static function (array $state): string {
    if ($state['provider'] !== 'postmark' || $state['transport'] !== 'postmark_api') {
        return 'STOP_DECISION_MISMATCH';
    }
    if (! $state['provider_neutral_core'] || $state['broadcast_or_marketing_scope']) {
        return 'STOP_SCOPE_OR_PORTABILITY';
    }
    if ($state['production_connection'] || $state['production_mutation'] || $state['provider_operation'] || $state['dns_change']) {
        return 'STOP_PRODUCTION_FREE_BOUNDARY';
    }

    $applicationReady = $state['postmark_package']
        && $state['http_client_package']
        && $state['message_stream_binding']
        && $state['delivery_ledger']
        && $state['outbound_dedupe']
        && $state['webhook_adapter']
        && $state['webhook_dedupe']
        && $state['bounded_retry'];

    if ($state['shared_state_created'] && ! $applicationReady) {
        return 'STOP_SHARED_STATE_BEFORE_APPLICATION';
    }
    if (! $applicationReady) {
        return 'APPLICATION_CORRECTIVE_REQUIRED';
    }
    if ($state['shared_state_created']) {
        return 'STOP_SHARED_STATE_NOT_AUTHORIZED';
    }

    return 'PROVIDER_HUMAN_GATES_READY';
};

$base = [
    'provider' => 'postmark',
    'transport' => 'postmark_api',
    'provider_neutral_core' => true,
    'broadcast_or_marketing_scope' => false,
    'postmark_package' => false,
    'http_client_package' => false,
    'message_stream_binding' => false,
    'delivery_ledger' => false,
    'outbound_dedupe' => false,
    'webhook_adapter' => false,
    'webhook_dedupe' => false,
    'bounded_retry' => false,
    'shared_state_created' => false,
    'provider_operation' => false,
    'production_connection' => false,
    'production_mutation' => false,
    'dns_change' => false,
];

$assert($evaluate($base) === 'APPLICATION_CORRECTIVE_REQUIRED', 'FROZEN_CANDIDATE_GAP_NOT_DETECTED');

$coupled = $base;
$coupled['provider_neutral_core'] = false;
$assert($evaluate($coupled) === 'STOP_SCOPE_OR_PORTABILITY', 'PROVIDER_COUPLING_NOT_REJECTED');

$broadcast = $base;
$broadcast['broadcast_or_marketing_scope'] = true;
$assert($evaluate($broadcast) === 'STOP_SCOPE_OR_PORTABILITY', 'SCOPE_EXPANSION_NOT_REJECTED');

$prematureShared = $base;
$prematureShared['shared_state_created'] = true;
$assert($evaluate($prematureShared) === 'STOP_SHARED_STATE_BEFORE_APPLICATION', 'PREMATURE_SHARED_STATE_NOT_REJECTED');

$mutation = $base;
$mutation['dns_change'] = true;
$assert($evaluate($mutation) === 'STOP_PRODUCTION_FREE_BOUNDARY', 'DNS_MUTATION_NOT_REJECTED');

$ready = array_replace($base, [
    'postmark_package' => true,
    'http_client_package' => true,
    'message_stream_binding' => true,
    'delivery_ledger' => true,
    'outbound_dedupe' => true,
    'webhook_adapter' => true,
    'webhook_dedupe' => true,
    'bounded_retry' => true,
]);
$assert($evaluate($ready) === 'PROVIDER_HUMAN_GATES_READY', 'CORRECTIVE_READY_STATE_NOT_RECOGNIZED');

$readyWithShared = $ready;
$readyWithShared['shared_state_created'] = true;
$assert($evaluate($readyWithShared) === 'STOP_SHARED_STATE_NOT_AUTHORIZED', 'SHARED_STATE_AUTHORIZATION_BOUNDARY_NOT_ENFORCED');

$wrongProvider = $base;
$wrongProvider['provider'] = 'ses';
$assert($evaluate($wrongProvider) === 'STOP_DECISION_MISMATCH', 'PROVIDER_DECISION_NOT_BOUND');

fwrite(STDOUT, "G5_POSTMARK_PRODUCTION_MAIL_SIMULATION=PASS\n");
fwrite(STDOUT, "scenarios=8\n");
fwrite(STDOUT, "assertions={$assertions}\n");
fwrite(STDOUT, "frozen_candidate_disposition=unchanged_no_refreeze\n");
fwrite(STDOUT, "current_state=application_corrective_required\n");
fwrite(STDOUT, "next_human_gate=G5_POSTMARK_APPLICATION_CORRECTIVE\n");
fwrite(STDOUT, "production_connection_attempted=false\n");
fwrite(STDOUT, "production_mutation=false\n");
fwrite(STDOUT, "provider_operation=false\n");
fwrite(STDOUT, "dns_change=false\n");
fwrite(STDOUT, "secret_values_output=false\n");
fwrite(STDOUT, "deploy_authorized=false\n");
fwrite(STDOUT, "PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE\n");
