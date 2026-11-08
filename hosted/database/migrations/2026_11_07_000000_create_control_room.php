<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('workers',function(Blueprint $table){$table->id();$table->string('identity')->unique();$table->string('name');$table->text('snapshot');$table->boolean('control_enabled')->default(false);$table->timestamp('last_seen_at');});
        Schema::create('commands',function(Blueprint $table){$table->uuid('id')->primary();$table->foreignId('worker_id')->constrained();$table->string('operation');$table->text('payload');$table->string('state')->default('queued');$table->text('result')->nullable();$table->timestamps();});
        Schema::create('videos',function(Blueprint $table){$table->id();$table->foreignId('worker_id')->constrained();$table->unsignedInteger('job_id');$table->string('sha256',64);$table->string('path');$table->unique(['worker_id','job_id']);$table->timestamps();});
    }
    public function down(): void {Schema::dropIfExists('videos');Schema::dropIfExists('commands');Schema::dropIfExists('workers');}
};
