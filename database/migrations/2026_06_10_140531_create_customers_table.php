<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers are the people who write in for help. They are identified by
 * email address only — they have no user account and never log in. This is
 * a core HelpSpot idea: staff are `users`, the public are `customers`, and
 * the two never share a table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Every helpdesk table is scoped to a team (the "installation").
            // foreignId() creates an unsigned big integer column; constrained()
            // adds the foreign key referencing teams.id by naming convention.
            $table->foreignId('team_id')->constrained();

            $table->string('name');
            $table->string('email');
            $table->timestamps();

            // A composite unique index: the same email may exist on two
            // different teams, but only once per team. A plain unique()
            // on email alone would be wrong in a multi-team schema.
            $table->unique(['team_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
