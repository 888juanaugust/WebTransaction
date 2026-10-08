<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One approval engine for every document type: a request per document
 * version (the rule it fell under, frozen once someone decides), and the
 * decisions taken on it, which only ever grow. The approver pivots stay the
 * source of who may approve; `sort` gives the order for "every approver, in
 * order".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type', 60);
            $table->unsignedBigInteger('approvable_id');
            $table->string('transaction_type', 40);
            $table->foreignId('transaction_approver_id')->nullable()->constrained('transaction_approvers')->nullOnDelete();
            // any_one | at_least_two | all_any_order | all_in_order, or 'right' when no rule covers the document
            $table->string('rule', 20);
            $table->json('slots')->nullable(); // [{kind: user|group, id, name}] in approval order
            $table->unsignedSmallInteger('required_count')->default(1);
            $table->bigInteger('amount')->default(0);
            $table->string('fingerprint', 64);
            $table->string('status', 12)->default('awaiting'); // awaiting | approved | rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['status', 'superseded_at']);
        });
        DB::statement('CREATE UNIQUE INDEX approval_requests_one_active ON approval_requests (approvable_type, approvable_id) WHERE superseded_at IS NULL');

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision', 10); // approved | rejected
            $table->unsignedSmallInteger('slot')->nullable(); // which approver slot the decision filled
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['approval_request_id', 'decision']);
        });
        DB::unprepared('CREATE TRIGGER approval_decisions_append_only BEFORE UPDATE OR DELETE ON approval_decisions FOR EACH ROW EXECUTE FUNCTION ledger_append_only();');

        foreach (['transaction_approver_users', 'transaction_approver_groups'] as $pivot) {
            Schema::table($pivot, fn (Blueprint $table) => $table->unsignedSmallInteger('sort')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['transaction_approver_users', 'transaction_approver_groups'] as $pivot) {
            Schema::table($pivot, fn (Blueprint $table) => $table->dropColumn('sort'));
        }
        DB::unprepared('DROP TRIGGER IF EXISTS approval_decisions_append_only ON approval_decisions');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};
