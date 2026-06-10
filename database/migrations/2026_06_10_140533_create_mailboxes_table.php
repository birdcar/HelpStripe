<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailboxes are inbound email identities: `support@example.com` is a
 * mailbox. Phase 3's email pipeline matches inbound mail to a mailbox by
 * address and files the resulting request under the mailbox's default
 * category.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->string('name');

            // Globally unique: one email address can only ever route to
            // one mailbox, regardless of team.
            $table->string('address')->unique();

            // The default category new requests land in. nullable() because
            // a mailbox may deliberately leave categorization to staff.
            // constrained() infers the categories table from the column name.
            $table->foreignId('category_id')->nullable()->constrained();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
