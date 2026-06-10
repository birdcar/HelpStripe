<?php

use App\Enums\RequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core helpdesk table. "Request" is HelpSpot's word for what other
 * helpdesks call a ticket. Note the migration ordering lesson: this file's
 * timestamp sorts *after* customers/categories/mailboxes because every
 * foreign key here must reference a table that already exists — Laravel
 * runs migrations in filename order.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            // The auto-increment id doubles as the public request number
            // customers see ("Request #1042") — no separate counter needed.
            $table->id();

            $table->foreignId('team_id')->constrained();
            $table->foreignId('customer_id')->constrained();

            // nullable() FKs: a request can arrive uncategorized, outside
            // any mailbox (portal/API), or unassigned (nobody owns it yet).
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('mailbox_id')->nullable()->constrained();

            // The column is `assigned_to` but it references users.id, so the
            // table name must be given explicitly — constrained() can only
            // infer the table when the column is named `user_id`.
            $table->foreignId('assigned_to')->nullable()->constrained('users');

            $table->string('subject');

            // Enum-backed columns are stored as plain strings; the model's
            // casts() turns them into RequestStatus / RequestSource enums.
            $table->string('status')->default(RequestStatus::Active->value);
            $table->string('source');

            $table->boolean('is_urgent')->default(false);

            // The portal lookup credential: email + access_key retrieves a
            // request without an account. Generated in a `creating` event.
            $table->string('access_key', 16);

            // SLA + lifecycle timestamps. nullable() = "hasn't happened yet".
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Composite indexes matching the queue's hottest query shapes:
            // "open requests for this team" and "my open requests".
            $table->index(['team_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
