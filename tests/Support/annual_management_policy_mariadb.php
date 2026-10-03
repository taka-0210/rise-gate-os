<?php

use App\Models\AnnualManagementPolicy;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyRelationService;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyLifecycle;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $code): never {
    fwrite(STDERR, $code.PHP_EOL);
    exit(1);
};
set_exception_handler(static function (Throwable $exception): never {
    echo '35A_EXCEPTION_CLASS='.str_replace('\\', '.', $exception::class).PHP_EOL;
    echo '35A_EXCEPTION_CODE='.(string) $exception->getCode().PHP_EOL;
    if ($exception instanceof Illuminate\Database\QueryException) {
        echo '35A_SQLSTATE='.(string) ($exception->errorInfo[0] ?? 'unknown').PHP_EOL;
        echo '35A_DRIVER_ERROR='.(string) ($exception->errorInfo[1] ?? 'unknown').PHP_EOL;
        if (preg_match("/Identifier name '([^']+)' is too long/", $exception->getMessage(), $matches) === 1) {
            echo '35A_OVERSIZED_IDENTIFIER='.$matches[1].PHP_EOL;
        }
    }
    echo '35A_EXCEPTION_SHA256='.hash('sha256', $exception->getMessage()).PHP_EOL;
    exit(1);
});
if (getenv('ANNUAL_POLICY_MARIADB_APPROVED') !== '1' || config('database.default') !== 'mariadb') {
    $fail('35A_MARIADB_GUARD_REJECTED');
}
$database = (string) config('database.connections.mariadb.database');
$host = (string) config('database.connections.mariadb.host');
if (! str_starts_with($database, 'company_os_35a_') || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    $fail('35A_MARIADB_BOUNDARY_REJECTED');
}

$migrationExit = Artisan::call('migrate:fresh', ['--force' => true]);
if ($migrationExit !== 0) {$fail('35A_MIGRATION_FAILED');}
$organization = Organization::create(['name'=>'35A MariaDB Fixture','slug'=>'35a-mariadb-'.strtolower((string) Str::ulid())]);
$owner = User::factory()->create();
$membership = OrganizationUser::create([
    'organization_id'=>$organization->id,'user_id'=>$owner->id,'role'=>OrganizationUser::ROLE_OWNER,
    'organization_role'=>OrganizationUser::ORGANIZATION_ROLE_OWNER,'membership_status'=>OrganizationUser::STATUS_ACTIVE,
    'access_epoch'=>1,'permissions'=>[],'joined_at'=>now(),
]);
$period = app(ManagementPeriodWriter::class)->register($owner,$organization,'2026年度','2026-12-01','2027-11-30',(string) Str::uuid(),23);
$policy = app(AnnualManagementPolicyPermissionManager::class)->initialize($owner,$organization,$period,(string) Str::uuid());
app(AnnualManagementPolicyPermissionManager::class)->update($owner,$organization,$policy,AnnualManagementPolicy::VIEW_SCOPE_EXPLICIT,[
    $membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true],
],(string) Str::uuid());
$group=OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'MariaDB部']);
$writer=app(AnnualManagementPolicyWriter::class);
$saved=$writer->saveDraft($owner,$policy,[
    'period_name'=>'2026年度','starts_on'=>'2026-12-01','ends_on'=>'2027-11-30','purpose'=>null,'background'=>null,
    'policy'=>'MariaDB上の正式方針','themes'=>[['statement'=>'Theme','priorities'=>[['statement'=>'Priority']]]],
    'departments'=>[['group_public_id'=>$group->public_id,'statements'=>[['statement'=>'Department Policy']]]],
],0,(string) Str::uuid());
$preview=$writer->preview($owner,$saved);
$revision=$writer->approve($owner,$saved,1,null,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
$priority=$saved->fresh('themes.priorities')->themes->first()->priorities->first();
app(AnnualManagementPolicyRelationService::class)->confirm($owner,$saved->fresh(),'priority',$priority->public_id,'group',$group->public_id,0,null,(string) Str::uuid());
$version=DB::selectOne('SELECT VERSION() AS value')->value;
$collation=DB::selectOne('SELECT @@character_set_database AS charset_value, @@collation_database AS collation_value');
$checks=[
    'mariadb_10_11'=>str_starts_with((string)$version,'10.11.'),
    'revision_one'=>$revision->revision_no===1,
    'snapshot_policy'=>$revision->snapshot['annual']['policy']==='MariaDB上の正式方針',
    'snapshot_schema_two'=>$revision->snapshot_schema_version===2,
    'fiscal_term_number'=>$period->fresh()->fiscal_term_number===23,
    'snapshot_fiscal_term_number'=>$revision->snapshot['annual']['period']['organization_fiscal_term_number']===23,
    'approved_upcoming'=>app(AnnualManagementPolicyLifecycle::class)->evaluate($period->fresh(),true,'2026-10-03')['effective_status']==='upcoming',
    'period_columns'=>Schema::hasColumn('organization_management_periods','fiscal_term_number')
        && Schema::hasColumn('organization_management_period_versions','fiscal_term_number'),
    'relation_version'=>$saved->fresh()->relation_version===1,
    'migration_count'=>(int)DB::table('migrations')->count()>0,
];
if (in_array(false,$checks,true)) {$fail('35A_MARIADB_ASSERTION_FAILED');}
$rollbackExit=Artisan::call('migrate:rollback',['--force'=>true,'--step'=>3]);
$checks['rollback_exit_zero']=$rollbackExit===0;
$checks['annual_tables_removed']=!Schema::hasTable('annual_management_policies')
    && !Schema::hasTable('annual_management_policy_relations');
$checks['period_columns_removed']=!Schema::hasColumn('organization_management_periods','fiscal_term_number')
    && !Schema::hasColumn('organization_management_period_versions','fiscal_term_number');
$checks['baseline_tables_preserved']=Schema::hasTable('organizations') && Schema::hasTable('management_design_items');
if (in_array(false,$checks,true)) {
    echo '35A_FAILED_CHECKS='.implode(',',array_keys(array_filter($checks,fn($value)=>$value===false))).PHP_EOL;
    $fail('35A_MARIADB_ROLLBACK_ASSERTION_FAILED');
}
$evidence = json_encode([
    'status'=>'PASS','engine_version'=>$version,'charset'=>$collation->charset_value,'collation'=>$collation->collation_value,
    'checks'=>$checks,'production_connection'=>false,'production_mutation'=>false,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$evidencePath = (string) getenv('ANNUAL_POLICY_MARIADB_EVIDENCE_PATH');
if ($evidencePath === '' || ! str_starts_with(basename($evidencePath), '35a-mariadb-evidence-')) {$fail('35A_EVIDENCE_PATH_REJECTED');}
file_put_contents($evidencePath, $evidence.PHP_EOL, LOCK_EX);
echo '35A_TERMINAL_EVIDENCE_WRITTEN'.PHP_EOL;
