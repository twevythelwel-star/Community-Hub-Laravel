<?php

namespace App\Models;

use App\Enums\PassCategory;
use App\Enums\RepresentativePermission;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Replaces the mockUserDatabase in src/context/auth-context.tsx with real,
 * hashed-credential accounts. `uid` preserves the original string identifier
 * shape so existing front-end code keyed on user.uid keeps working.
 */
class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, InteractsWithMedia, Notifiable, Searchable;

    protected $fillable = [
        'uid', 'name', 'display_name', 'email', 'phone', 'role', 'title',
        'lot', 'street', 'avatar_url', 'status', 'password', 'ai_consent',
        'deactivated_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'ai_consent' => 'boolean',
            'role' => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->uid ??= (string) Str::uuid();
            $user->display_name ??= $user->name;
        });
    }

    // ── Relationships ────────────────────────────────────────────────

    public function person(): HasOne
    {
        return $this->hasOne(Person::class);
    }

    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }

    public function activePass(): HasOne
    {
        return $this->hasOne(GatePass::class)->active()->latestOfMany();
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class)->orderBy('id');
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class, 'homeowner_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'added_by');
    }

    public function renter(): HasOne
    {
        return $this->hasOne(Renter::class);
    }

    public function temporaryOccupancies(): HasMany
    {
        return $this->hasMany(Renter::class, 'homeowner_id');
    }

    public function renters(): HasMany
    {
        return $this->temporaryOccupancies();
    }

    public function activityLog(): HasMany
    {
        return $this->hasMany(ActivityLogEntry::class)->latest('occurred_at');
    }

    public function accessLogEntries(): HasMany
    {
        return $this->hasMany(AccessLogEntry::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentCustomers(): HasMany
    {
        return $this->hasMany(PaymentCustomer::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'owner_user_id');
    }

    public function authorizedAppointments(): HasMany
    {
        return $this->hasMany(AuthorizedPerson::class);
    }

    public function activeAuthorizedAppointments(): HasMany
    {
        return $this->hasMany(AuthorizedPerson::class)->current();
    }

    /**
     * All ownership entities this user currently represents.
     *
     * @return Collection<int, OwnershipEntity>
     */
    public function representedEntities(): Collection
    {
        return OwnershipEntity::whereHas('authorizedPeople', fn ($q) => $q->where('user_id', $this->id)->current())->get();
    }

    /**
     * Properties this user owns directly, plus those of each entity they
     * currently represent, within that appointment's property scope.
     *
     * @return Collection<int, Property>
     */
    public function allAuthorizedProperties(): Collection
    {
        $entityProperties = $this->activeAuthorizedAppointments()->with('ownershipEntity.properties')->get()
            ->flatMap(fn (AuthorizedPerson $person) => $person->authorizedProperties());

        return $this->properties()->get()->concat($entityProperties)->unique('id')->values();
    }

    /**
     * Retrieve the properties of a specific OwnershipEntity that this user is authorized to manage.
     *
     * @return Collection<int, Property>
     */
    public function authorizedPropertiesForEntity(OwnershipEntity $entity): Collection
    {
        if ($this->role->isAdministrative()) {
            return $entity->properties()->get();
        }

        $person = $entity->activeAuthorizedPeople()->where('user_id', $this->id)->first();
        if (! $person) {
            return new Collection;
        }

        return $person->authorizedProperties();
    }

    /**
     * Properties whose visitors this user may manage: their own, and those an
     * appointment in force lets them manage visitors for, within its scope.
     *
     * @return Collection<int, Property>
     */
    public function propertiesForVisitorManagement(): Collection
    {
        $entityProperties = $this->activeAuthorizedAppointments()->with('ownershipEntity.properties')->get()
            ->filter(fn (AuthorizedPerson $person) => $person->hasPermission(RepresentativePermission::ManageVisitors->value))
            ->flatMap(fn (AuthorizedPerson $person) => $person->authorizedProperties());

        return $this->properties()->get()->concat($entityProperties)->unique('id')->values();
    }

    public function canManageVisitorsForProperty(Property|int $property): bool
    {
        $propertyId = $property instanceof Property ? $property->id : $property;

        return $this->propertiesForVisitorManagement()->contains('id', $propertyId);
    }

    public function canManageVisitorsForAnyProperty(): bool
    {
        return $this->propertiesForVisitorManagement()->isNotEmpty();
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function warningResponses(): HasMany
    {
        return $this->hasMany(WarningResponse::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function autoPaySetting(): HasOne
    {
        return $this->hasOne(AutoPaySetting::class);
    }

    public function safetyProfile(): HasOne
    {
        return $this->hasOne(SafetyProfile::class);
    }

    public function delegatedAccessesGranted(): HasMany
    {
        return $this->hasMany(DelegatedAccess::class, 'grantor_user_id');
    }

    public function delegatedAccessesReceived(): HasMany
    {
        return $this->hasMany(DelegatedAccess::class, 'delegate_user_id');
    }

    public function emergencyContinuityPlan(): HasOne
    {
        return $this->hasOne(EmergencyContinuityPlan::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class, 'created_by');
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class)->latest();
    }

    public function unreadInAppNotificationsCount(): int
    {
        return $this->inAppNotifications()->unread()->count();
    }

    // ── Domain helpers ───────────────────────────────────────────────

    public function isActive(): bool
    {
        if ($this->status !== 'Active' || $this->deactivated_at !== null) {
            return false;
        }

        if ($this->role === UserRole::TemporaryHomeowner) {
            $renter = $this->relationLoaded('renter') ? $this->renter : $this->renter()->first();
            if ($renter && $renter->lease_end && $renter->lease_end->isPast()) {
                return false;
            }
        }

        return true;
    }

    public function temporaryStayExpirationMessage(): ?string
    {
        if ($this->role === UserRole::TemporaryHomeowner) {
            $renter = $this->relationLoaded('renter') ? $this->renter : $this->renter()->first();
            if ($renter && $renter->lease_end && $renter->lease_end->isPast()) {
                return "Your temporary homeowner access expired on {$renter->lease_end->format('M d, Y')}. Contact the property owner to extend access.";
            }
        }

        return null;
    }

    public function activeStay(): ?Renter
    {
        return $this->renter()->active()->first();
    }

    public function isLongTermRenter(): bool
    {
        return $this->role === UserRole::TemporaryHomeowner
            || ($this->relationLoaded('renter') ? ($this->renter !== null) : $this->renter()->exists());
    }

    public function passCategory(): PassCategory
    {
        // A staff member attached to a specific residence carries the
        // household-staff category rather than the community-staff one.
        if ($this->role === UserRole::Staff && filled($this->lot)) {
            return PassCategory::HomeownerStaff;
        }

        return $this->role->passCategory();
    }

    /** Property label used on passes and in the directory, e.g. "Lot 42, Royal Palm Drive". */
    public function propertyLabel(): string
    {
        $direct = collect([$this->lot, $this->street])->filter()->implode(', ');
        if ($direct !== '') {
            return $direct;
        }

        $authorizedProperty = $this->allAuthorizedProperties()->first();
        if ($authorizedProperty) {
            return $authorizedProperty->label();
        }

        return 'Unassigned';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Active')->whereNull('deactivated_at');
    }

    public function scopeRole($query, UserRole|string $role)
    {
        return $query->where('role', $role instanceof UserRole ? $role->value : $role);
    }

    public function recordActivity(string $action): ActivityLogEntry
    {
        return $this->activityLog()->create([
            'action' => $action,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Register media collections for profile picture / avatar and documents.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml']);

        $this->addMediaCollection('documents')
            ->acceptsMimeTypes([
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/jpeg',
                'image/png',
            ]);
    }

    /**
     * Register media conversions for thumbnails and previews.
     */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(120)
            ->height(120)
            ->nonQueued();

        $this->addMediaConversion('preview')
            ->width(400)
            ->height(400)
            ->nonQueued();
    }

    /**
     * Seamlessly return media library avatar or fallback to original avatar_url.
     */
    public function getAvatarUrlAttribute(?string $value): ?string
    {
        if ($this->hasMedia('avatar')) {
            return $this->getFirstMediaUrl('avatar');
        }

        return $value;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role instanceof \BackedEnum ? $this->role->value : (string) $this->role,
            'lot' => $this->lot,
            'street' => $this->street,
        ];
    }
}
