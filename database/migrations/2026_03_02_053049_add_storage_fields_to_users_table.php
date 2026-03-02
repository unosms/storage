<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('user')->after('password');
            }
            if (! Schema::hasColumn('users', 'can_upload')) {
                $table->boolean('can_upload')->default(true)->after('role');
            }
            if (! Schema::hasColumn('users', 'can_view_monitoring')) {
                $table->boolean('can_view_monitoring')->default(true)->after('can_upload');
            }
            if (! Schema::hasColumn('users', 'can_manage_users')) {
                $table->boolean('can_manage_users')->default(false)->after('can_view_monitoring');
            }
            if (! Schema::hasColumn('users', 'quota_mb')) {
                $table->unsignedBigInteger('quota_mb')->default(10240)->after('can_manage_users');
            }
            if (! Schema::hasColumn('users', 'speed_limit_kbps')) {
                $table->unsignedInteger('speed_limit_kbps')->nullable()->after('quota_mb');
            }
            if (! Schema::hasColumn('users', 'home_directory')) {
                $table->string('home_directory')->default('/')->after('speed_limit_kbps');
            }
            if (! Schema::hasColumn('users', 'ftp_host')) {
                $table->string('ftp_host')->nullable()->after('home_directory');
            }
            if (! Schema::hasColumn('users', 'ftp_port')) {
                $table->unsignedSmallInteger('ftp_port')->default(21)->after('ftp_host');
            }
            if (! Schema::hasColumn('users', 'ftp_username')) {
                $table->string('ftp_username')->nullable()->after('ftp_port');
            }
            if (! Schema::hasColumn('users', 'ftp_password')) {
                $table->text('ftp_password')->nullable()->after('ftp_username');
            }
            if (! Schema::hasColumn('users', 'ftp_passive')) {
                $table->boolean('ftp_passive')->default(true)->after('ftp_password');
            }
            if (! Schema::hasColumn('users', 'ftp_ssl')) {
                $table->boolean('ftp_ssl')->default(false)->after('ftp_passive');
            }
            if (! Schema::hasColumn('users', 'used_space_bytes')) {
                $table->unsignedBigInteger('used_space_bytes')->default(0)->after('ftp_ssl');
            }
        });

        $firstUserId = DB::table('users')->orderBy('id')->value('id');
        if ($firstUserId) {
            DB::table('users')->where('id', $firstUserId)->update([
                'role' => 'admin',
                'can_manage_users' => true,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'role',
                'can_upload',
                'can_view_monitoring',
                'can_manage_users',
                'quota_mb',
                'speed_limit_kbps',
                'home_directory',
                'ftp_host',
                'ftp_port',
                'ftp_username',
                'ftp_password',
                'ftp_passive',
                'ftp_ssl',
                'used_space_bytes',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
