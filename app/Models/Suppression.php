<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use HasFactory;

    private const ASCII_LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

    private const ASCII_UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    protected $fillable = [
        'workspace_id',
        'project_id',
        'source_id',
        'email_id',
        'email',
        'reason',
        'event_type',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $email): string => self::normalizeEmail($email),
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return strtr(
            trim($email, ' '),
            self::ASCII_UPPERCASE,
            self::ASCII_LOWERCASE,
        );
    }

    /**
     * @param  Builder<Suppression>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(Email::class, 'email_id');
    }
}
