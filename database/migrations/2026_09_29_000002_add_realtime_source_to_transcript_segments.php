<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_common_shared_transcript_segments', function (Blueprint $table): void {
            $table->string('source_kind', 24)->default('bounded_audio')->after('ai_common_shared_capture_stream_id');
            $table->unsignedBigInteger('realtime_durable_final_commit_id')->nullable()->after('source_kind');
            $table->unsignedBigInteger('ai_common_shared_audio_window_id')->nullable()->change();
            $table->foreign('realtime_durable_final_commit_id', 'acsts_realtime_commit_fk')->references('id')->on('ai_common_shared_durable_final_commits')->restrictOnDelete();
            $table->index(['ai_common_shared_session_id', 'source_kind', 'range_start_ms', 'id'], 'acsts_session_source_range_idx');
        });
        $this->guard('CREATE');
    }

    public function down(): void
    {
        if (DB::table('ai_common_shared_transcript_segments')->where('source_kind', 'realtime_source')->exists()) {
            throw new RuntimeException('Realtime Transcript evidence exists; preserve history instead of destructive rollback.');
        }
        $this->guard('DROP');
        Schema::table('ai_common_shared_transcript_segments', function (Blueprint $table): void {
            $table->dropIndex('acsts_session_source_range_idx');
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['realtime_durable_final_commit_id']);
            } else {
                $table->dropForeign('acsts_realtime_commit_fk');
            }
            $table->dropColumn(['source_kind', 'realtime_durable_final_commit_id']);
            $table->unsignedBigInteger('ai_common_shared_audio_window_id')->nullable(false)->change();
        });
    }

    private function guard(string $action): void
    {
        foreach (['insert', 'update'] as $event) {
            $name = 'acsts_source_guard_'.$event;
            if ($action === 'DROP') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");

                continue;
            }
            $valid = "((NEW.source_kind = 'bounded_audio' AND NEW.ai_common_shared_audio_window_id IS NOT NULL AND NEW.realtime_durable_final_commit_id IS NULL) OR (NEW.source_kind = 'realtime_source' AND NEW.ai_common_shared_audio_window_id IS NULL AND NEW.realtime_durable_final_commit_id IS NOT NULL))";
            $sql = DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE ".strtoupper($event)." ON ai_common_shared_transcript_segments WHEN NOT {$valid} BEGIN SELECT RAISE(ABORT, 'invalid transcript source lineage'); END"
                : "CREATE TRIGGER {$name} BEFORE ".strtoupper($event)." ON ai_common_shared_transcript_segments FOR EACH ROW BEGIN IF NOT {$valid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid transcript source lineage'; END IF; END";
            DB::unprepared($sql);
        }
    }
};
