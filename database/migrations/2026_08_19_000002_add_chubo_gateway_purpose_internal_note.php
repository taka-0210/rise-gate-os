<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NOTE_MARKER = '【厨房君 APIゲートウェイ｜目的と目指す形】';

    public function up(): void
    {
        $project = DB::table('projects')->where('code', 'CHUBO-GW')->first();

        if (! $project || ! $project->owner_user_id) {
            return;
        }

        $alreadyExists = DB::table('project_internal_notes')
            ->where('project_id', $project->id)
            ->where('body', 'like', self::NOTE_MARKER.'%')
            ->exists();

        if ($alreadyExists) {
            return;
        }

        $now = now();

        DB::table('project_internal_notes')->insert([
            'project_id' => $project->id,
            'user_id' => $project->owner_user_id,
            'body' => self::NOTE_MARKER."\n\n"
                ."■ 目的\n"
                ."厨房君に蓄積された販売・買取・在庫・価格・会計データを、安全に外部業務とつなぐ共通基盤をつくる。PDFやExcelからの転記、価格計算、CSV作成、集計などの反復作業を減らし、スタッフが本来の確認・判断・提案に集中できる状態を目指す。個別の機能をその都度SEへ依頼する形から、業務ルールを蓄積しながら自分たちで改善を続けられる形へ移行する。\n\n"
                ."■ 目指す形\n"
                ."・スタッフがブラウザ上の業務ハブから必要な機能を選んで利用できる。\n"
                ."・PDFやExcelを読み込み、厨房君のフィールド名／画面項目名を割り当てた表形式で確認できる。\n"
                ."・利益率や丸めなどの業務ルールを自動計算し、登録前に人が修正・承認できる。\n"
                ."・見積番号など登録先を指定し、確認後に厨房君へ安全に反映できる。\n"
                ."・販売見積、買取見積、会計出力、価格更新、在庫分析、長期在庫、営業部、商品管理部の8機能を一つの入口にまとめる。\n"
                ."・AIは文書読解、項目推定、分析、提案など必要な場面だけで使い、計算・権限・登録処理はシステム側で確実に制御する。\n"
                ."・誤登録を防ぐため、確認画面、権限管理、監査ログ、重複防止、事前バックアップと復元手順を備える。\n\n"
                ."■ 現在地と再開地点\n"
                ."ローカル版 chubo-kun-gateway と販売見積データ登録の試作を作成済み。PDF明細の表形式表示、利益率計算、見積番号指定まで確認できている。次回は見積番号89176で検証した内容を基に、販売見積登録の本番DB接続、登録前チェック、バックアップ／復元設計から再開する。",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // The memo may be edited after registration, so rollback does not delete it.
    }
};
