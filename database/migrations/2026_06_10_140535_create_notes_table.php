<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes are the request timeline: every public reply and every private
 * internal note is a row here, whether written by staff or the customer.
 *
 * Authorship uses two nullable foreign keys (user_id for staff,
 * customer_id for customers) instead of a polymorphic relation. Exactly
 * one must be set — an invariant enforced in factories and actions rather
 * than a database CHECK constraint, keeping the SQLite schema simple to
 * teach. The tradeoff: the database itself won't reject a bad row.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete(): deleting a request deletes its timeline.
            // Notes have no meaning outside their request.
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();

            // Staff author XOR customer author — see class docblock.
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();

            $table->text('body');

            // Private notes are internal-only: the Phase 4 portal must
            // never render rows where is_private is true.
            $table->boolean('is_private')->default(false);

            // Which channel produced this note (RequestSource enum cast).
            $table->string('source');

            // RFC 5322 Message-ID for email threading: Phase 3 matches
            // inbound replies to requests via In-Reply-To/References headers.
            $table->string('message_id')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
