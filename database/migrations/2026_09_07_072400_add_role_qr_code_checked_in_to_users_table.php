<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('client')->after('password');
            $table->string('qr_code')->nullable()->unique()->after('role');
            $table->boolean('checked_in')->default(false)->after('qr_code');
        });

        DB::table('users')->whereNull('qr_code')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'qr_code' => (string) Str::uuid(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'qr_code', 'checked_in']);
        });
    }
};
