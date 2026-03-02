<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'can_upload' => 'boolean',
            'can_view_monitoring' => 'boolean',
            'can_manage_users' => 'boolean',
            'quota_mb' => 'integer',
            'speed_limit_kbps' => 'integer',
            'ftp_port' => 'integer',
            'ftp_passive' => 'boolean',
            'ftp_ssl' => 'boolean',
            'used_space_bytes' => 'integer',
            'ftp_password' => 'encrypted',
        ];
    }

    public function transferLogs(): HasMany
    {
        return $this->hasMany(TransferLog::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->can_manage_users;
    }

    public function quotaBytes(): int
    {
        return (int) $this->quota_mb * 1024 * 1024;
    }
}
