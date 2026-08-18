<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $riseGateOs = DB::table('projects')->where('code', 'RGOS')->first();

        if (! $riseGateOs) {
            return;
        }

        DB::transaction(function () use ($riseGateOs): void {
            $now = now();
            $projectId = DB::table('projects')
                ->where('owning_workspace_id', $riseGateOs->owning_workspace_id)
                ->where('code', 'CHUBO-GW')
                ->value('id');

            if (! $projectId) {
                $projectId = DB::table('projects')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'organization_id' => $riseGateOs->organization_id,
                    'owning_workspace_id' => $riseGateOs->owning_workspace_id,
                    'billing_workspace_id' => $riseGateOs->billing_workspace_id,
                    'client_id' => $riseGateOs->client_id,
                    'owner_user_id' => $riseGateOs->owner_user_id,
                    'name' => '厨房君 APIゲートウェイ',
                    'code' => 'CHUBO-GW',
                    'summary' => '厨房君とPDF・会計・在庫・部門分析をつなぐ社内業務ハブ。スタッフ向けクラウド運用は次回検討する。',
                    'current_state' => "ローカル試作 chubo-kun-gateway を作成済み。販売見積データ登録は、PDF明細の表形式確認、利益率計算、見積番号指定まで試作済み。厨房君DBへの本登録は未接続。\n\n搭載予定の8機能:\n1. 販売見積データ登録\n2. 買取見積データ登録\n3. 会計データエクスポート\n4. 価格一括更新\n5. 在庫データ分析\n6. 長期在庫チェック\n7. 営業部ダッシュボード\n8. 商品管理部ダッシュボード",
                    'desired_future_state' => 'スタッフがブラウザから利用でき、AIが必要な処理だけOpenAI APIを呼び出す。登録前確認、権限管理、監査ログ、重複防止、事前スナップショットと復元手順を備える。再開時は販売見積データ登録の本番DB接続設計から進める。',
                    'status' => 'on_hold',
                    'priority' => 'normal',
                    'start_date' => $now->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('project_members')->insertOrIgnore([
                'project_id' => $projectId,
                'user_id' => $riseGateOs->owner_user_id,
                'workspace_id' => $riseGateOs->owning_workspace_id,
                'project_role' => 'owner',
                'permission_level' => 'admin',
                'invited_by' => $riseGateOs->owner_user_id,
                'invited_at' => $now,
                'accepted_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // The project may be edited after registration, so rollback does not delete it.
    }
};
