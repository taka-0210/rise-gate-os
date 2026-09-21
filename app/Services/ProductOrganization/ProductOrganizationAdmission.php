<?php

namespace App\Services\ProductOrganization;

use App\Models\Organization;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AccountAudit;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductOrganizationAdmission
{
    public const ENTRY_STAFF_PREPARE = 'staff_invitation_prepare';

    public const ENTRY_STAFF_ACCEPT = 'staff_invitation_accept';

    public const ENTRY_OWNER_PREPARE = 'owner_onboarding_prepare';

    public const ENTRY_OWNER_COMPLETE = 'owner_onboarding_complete';

    public const ENTRY_SYSTEM_ADMIN_WORKSPACE = 'system_admin_workspace_membership';

    public const ENTRY_BOOTSTRAP = 'bootstrap';

    public const ENTRY_CLIENT_PROMOTION = 'client_promotion';

    public function __construct(private readonly AccountAudit $audit) {}

    public function enabled(): bool
    {
        return (bool) config('product_ux.organization_admission_enabled');
    }

    public function registerUnstarted(User $user, string $evidenceRef): ?ProductAccountEligibility
    {
        if (! $this->enabled()) {
            return null;
        }

        return ProductAccountEligibility::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'mode' => ProductAccountEligibility::MODE_UNSTARTED,
                'product_organization_id' => null,
                'classification_version' => config('product_ux.classification_version'),
                'classified_at' => now(),
                'evidence_ref' => $evidenceRef,
            ],
        );
    }

    public function registerSingle(User $user, Organization $organization, string $evidenceRef): ?ProductAccountEligibility
    {
        if (! $this->enabled()) {
            return null;
        }

        return ProductAccountEligibility::query()->create([
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
            'classification_version' => config('product_ux.classification_version'),
            'classified_at' => now(),
            'evidence_ref' => $evidenceRef,
        ]);
    }

    public function checkExistingOrganization(User $user, Organization $organization, string $entryCode): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->execute($user, $entryCode, $organization, false, static fn (): null => null);
    }

    public function prepareExistingOrganization(
        User $user,
        Organization $organization,
        string $entryCode,
        Closure $callback,
    ): mixed {
        if (! $this->enabled()) {
            return $callback();
        }

        return $this->execute($user, $entryCode, $organization, false, $callback);
    }

    public function admitExistingOrganization(
        User $user,
        Organization $organization,
        string $entryCode,
        Closure $callback,
    ): mixed {
        if (! $this->enabled()) {
            return $callback();
        }

        return $this->execute($user, $entryCode, $organization, true, $callback);
    }

    public function assertMayStartNewOrganization(User $user, string $entryCode): void
    {
        if (! $this->enabled()) {
            return;
        }

        $eligibility = ProductAccountEligibility::query()
            ->where('user_id', $user->id)
            ->first();
        if (! $eligibility) {
            $this->reject($user, new ProductOrganizationAdmissionRejected('eligibility_missing', $entryCode));
        }
        if ($eligibility->mode !== ProductAccountEligibility::MODE_UNSTARTED) {
            $reason = $eligibility->mode === ProductAccountEligibility::MODE_REVIEW_REQUIRED
                ? 'review_required'
                : 'second_organization';
            $this->reject($user, new ProductOrganizationAdmissionRejected($reason, $entryCode));
        }
    }

    public function admitNewOrganization(
        User $user,
        string $entryCode,
        Closure $callback,
        ?Closure $idempotentOrganizationResolver = null,
    ): mixed {
        if (! $this->enabled()) {
            $outcome = $callback();

            return $outcome['result'];
        }

        return $this->execute(
            $user,
            $entryCode,
            null,
            true,
            $callback,
            $idempotentOrganizationResolver,
            true,
        );
    }

    public function rejectNewOrganization(User $user, string $entryCode): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->reject($user, new ProductOrganizationAdmissionRejected('entry_retired', $entryCode));
    }

    private function execute(
        User $user,
        string $entryCode,
        ?Organization $targetOrganization,
        bool $bindOnSuccess,
        Closure $callback,
        ?Closure $targetOrganizationResolver = null,
        bool $expectsNewOrganizationOutcome = false,
    ): mixed {
        $attempt = 0;
        beginning:
        try {
            return DB::transaction(function () use ($user, $entryCode, $targetOrganization, $bindOnSuccess, $callback, $targetOrganizationResolver, $expectsNewOrganizationOutcome): mixed {
                $eligibility = ProductAccountEligibility::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                if (! $eligibility) {
                    throw new ProductOrganizationAdmissionRejected(
                        'eligibility_missing',
                        $entryCode,
                        $targetOrganization?->public_id,
                    );
                }

                $organization = $targetOrganization;
                if ($organization === null && $targetOrganizationResolver !== null) {
                    $organization = $targetOrganizationResolver();
                    if ($organization !== null && ! $organization instanceof Organization) {
                        throw new \LogicException('Idempotent organization resolver must return an organization or null.');
                    }
                }

                $this->assertAllowed($eligibility, $organization, $entryCode);
                $outcome = $callback();
                $result = $outcome;
                if ($expectsNewOrganizationOutcome) {
                    if (! is_array($outcome) || ! ($outcome['organization'] ?? null) instanceof Organization
                        || ! array_key_exists('result', $outcome)) {
                        throw new \LogicException('New organization admission callback must return result and organization.');
                    }
                    if ($organization !== null && $organization->id !== $outcome['organization']->id) {
                        throw new \LogicException('Idempotent organization result does not match the bound organization.');
                    }
                    $organization ??= $outcome['organization'];
                    $result = $outcome['result'];
                }

                if ($bindOnSuccess && $eligibility->mode === ProductAccountEligibility::MODE_UNSTARTED) {
                    $updated = ProductAccountEligibility::query()
                        ->whereKey($eligibility->id)
                        ->where('mode', ProductAccountEligibility::MODE_UNSTARTED)
                        ->whereNull('product_organization_id')
                        ->update([
                            'mode' => ProductAccountEligibility::MODE_SINGLE,
                            'product_organization_id' => $organization->id,
                            'classified_at' => now(),
                            'evidence_ref' => 'admission:'.$entryCode,
                            'updated_at' => now(),
                        ]);
                    if ($updated !== 1) {
                        throw new ProductOrganizationAdmissionRejected(
                            'concurrent_binding_conflict',
                            $entryCode,
                            $organization->public_id,
                        );
                    }
                    $this->audit->record(
                        'account.product_organization.bound',
                        'success',
                        $user,
                        $user,
                        [
                            'entry_code' => $entryCode,
                            'target_organization_public_id' => $organization->public_id,
                            'classification_version' => config('product_ux.classification_version'),
                            'timezone' => config('app.timezone'),
                        ],
                    );
                }

                return $result;
            }, 3);
        } catch (ProductOrganizationAdmissionRejected $exception) {
            $this->reject($user, $exception);
        } catch (QueryException $exception) {
            if (++$attempt < 4 && $this->isRetryable($exception)) {
                DB::disconnect();
                usleep(25_000 * $attempt);
                goto beginning;
            }
            throw $exception;
        }
    }

    private function assertAllowed(
        ProductAccountEligibility $eligibility,
        ?Organization $organization,
        string $entryCode,
    ): void {
        if ($eligibility->mode === ProductAccountEligibility::MODE_REVIEW_REQUIRED) {
            throw new ProductOrganizationAdmissionRejected('review_required', $entryCode, $organization?->public_id);
        }
        if ($eligibility->mode === ProductAccountEligibility::MODE_SINGLE) {
            if (! $organization || $eligibility->product_organization_id !== $organization->id) {
                throw new ProductOrganizationAdmissionRejected('second_organization', $entryCode, $organization?->public_id);
            }

            return;
        }
        if ($eligibility->mode === ProductAccountEligibility::MODE_LEGACY_MULTI) {
            $compatible = $organization && $eligibility->compatibilities()
                ->whereHas('organizationMembership', fn ($query) => $query
                    ->where('user_id', $eligibility->user_id)
                    ->where('organization_id', $organization->id))
                ->exists();
            if (! $compatible) {
                throw new ProductOrganizationAdmissionRejected('outside_legacy_compatibility', $entryCode, $organization?->public_id);
            }
        }
    }

    private function reject(User $user, ProductOrganizationAdmissionRejected $exception): never
    {
        try {
            $this->audit->record(
                'account.product_organization.admission_rejected',
                'rejected',
                $user,
                $user,
                array_filter([
                    'reason_code' => $exception->reasonCode,
                    'entry_code' => $exception->entryCode,
                    'target_organization_public_id' => $exception->targetOrganizationPublicId,
                    'timezone' => config('app.timezone'),
                ], static fn (mixed $value): bool => $value !== null),
            );
        } catch (Throwable) {
            // Rejection must not become an information-disclosure side channel.
        }

        throw ValidationException::withMessages([
            'product_organization' => $exception->getMessage(),
        ]);
    }

    private function isRetryable(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout');
    }
}
