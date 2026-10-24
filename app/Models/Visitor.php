<?php

namespace App\Models;

use App\Enums\VisitorStatus;
use App\Jobs\SendVisitorPassNotification;
use App\Services\Messaging\PhoneNumber;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

class Visitor extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'name', 'contact', 'vehicle', 'id_type', 'id_number', 'type', 'share_token', 'status',
        'expected_at', 'arrival_window_start', 'arrival_window_end', 'date_range',
        'parking_instructions', 'community_rules', 'emergency_info', 'notes',
        'homeowner_id', 'homeowner_name', 'property_id',
        'id_image_url', 'is_blocked', 'checked_in_at', 'checked_out_at', 'expired_at',
        'notify_email', 'notify_sms', 'notify_whatsapp', 'qr_code_path',
    ];

    protected static function booted(): void
    {
        static::creating(function (Visitor $visitor) {
            if (empty($visitor->share_token)) {
                $visitor->share_token = bin2hex(random_bytes(16));
            }
        });
    }

    /**
     * A host's visitors: those they registered, and those registered to the
     * given properties (ones they own or manage for an owner).
     *
     * @param  Collection<int, int>  $propertyIds
     */
    public function scopeManageableBy(Builder $query, User $host, Collection $propertyIds): void
    {
        $query->where(fn (Builder $q) => $q->where('homeowner_id', $host->id)
            ->when($propertyIds->isNotEmpty(), fn (Builder $q) => $q->orWhereIn('property_id', $propertyIds)));
    }

    /**
     * Where the visit is: the property it was registered to (a representative's
     * account may cover several), else the host's own.
     */
    public function hostPropertyLabel(): string
    {
        return $this->property?->label() ?: ($this->homeowner?->propertyLabel() ?? 'Residential Lot');
    }

    public function guestPassUrl(): string
    {
        return route('guest-pass.show', ['token' => $this->share_token]);
    }

    protected function casts(): array
    {
        return [
            'expected_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'expired_at' => 'datetime',
            'is_blocked' => 'boolean',
            'notify_email' => 'boolean',
            'notify_sms' => 'boolean',
            'notify_whatsapp' => 'boolean',
            'community_rules' => 'array',
            'emergency_info' => 'array',
            'status' => VisitorStatus::class,
        ];
    }

    public function arrivalWindowLabel(): string
    {
        if (! empty($this->arrival_window_start) && ! empty($this->arrival_window_end)) {
            $formatTime = function ($timeStr) {
                try {
                    return Carbon::createFromFormat('H:i', substr($timeStr, 0, 5))->format('g:i A');
                } catch (\Throwable) {
                    return $timeStr;
                }
            };

            return sprintf('%s – %s', $formatTime($this->arrival_window_start), $formatTime($this->arrival_window_end));
        }

        if ($this->expected_at) {
            $start = $this->expected_at->format('g:i A');
            $end = $this->expected_at->copy()->addHours(4)->format('g:i A');

            return "{$start} – {$end}";
        }

        return 'Scheduled Arrival Window';
    }

    /** The visitor's own instructions, else the estate's (config/visitor_pass.php), else none. */
    public function parkingInstructions(): string
    {
        return (string) ($this->parking_instructions ?: config('visitor_pass.parking_instructions') ?: '');
    }

    public function communityRules(): array
    {
        return ! empty($this->community_rules) ? $this->community_rules : static::defaultCommunityRules();
    }

    /** Only items actually set, for this visitor or for the estate. */
    public function emergencyInfo(): array
    {
        return array_filter(
            array_merge(static::defaultEmergencyInfo(), (array) ($this->emergency_info ?? [])),
            fn ($value) => filled($value),
        );
    }

    /**
     * The estate's rules for visitors, from config/visitor_pass.php. These
     * were invented (a 15 MPH limit, quiet hours, escort rules) and shown to
     * every guest as the estate's own.
     */
    public static function defaultCommunityRules(): array
    {
        return (array) config('visitor_pass.community_rules', []);
    }

    /**
     * From config/visitor_pass.php, unset items left out. The fallback was a
     * 555 gatehouse number, "911" for ambulance and fire, and an invented AED
     * location and assembly point.
     */
    public static function defaultEmergencyInfo(): array
    {
        return array_filter((array) config('visitor_pass.emergency_info', []), fn ($value) => filled($value));
    }

    public function homeowner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeowner_id');
    }

    /** Which of the host's properties they are visiting; null means the host's address. */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeExpected($query)
    {
        return $query->where('status', VisitorStatus::Expected->value)->whereNull('expired_at');
    }

    /**
     * Expected visitors who never arrived within the grace period.
     *
     * The original swept these in a browser setInterval, so it only ran while
     * somebody had the visitors page open and removed nothing from storage.
     */
    public function scopeNoShow($query, int $graceHours = 12)
    {
        return $query->where('status', VisitorStatus::Expected->value)
            ->whereNull('expired_at')
            ->where('expected_at', '<', now()->subHours($graceHours));
    }

    public function scopeOnSite($query)
    {
        return $query->where('status', VisitorStatus::CheckedIn->value);
    }

    public function checkIn(): void
    {
        $this->update(['status' => VisitorStatus::CheckedIn, 'checked_in_at' => now()]);
    }

    public function checkOut(): void
    {
        $this->update(['status' => VisitorStatus::CheckedOut, 'checked_out_at' => now()]);
    }

    /**
     * The channels this visitor's pass can actually go out on: the ones they
     * opted into, that suit the contact given, and that are configured. An
     * email address is not texted, and SMS is not attempted with no Twilio
     * account behind it.
     *
     * @return list<string>
     */
    public function passNotificationChannels(): array
    {
        $isEmail = is_string($this->contact) && filter_var($this->contact, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = PhoneNumber::toE164($this->contact) !== null;

        return array_values(array_filter([
            $this->notify_email && $isEmail ? 'email' : null,
            $this->notify_sms && $isPhone && app(SmsService::class)->isConfigured() ? 'sms' : null,
            $this->notify_whatsapp && $isPhone && app(WhatsAppService::class)->isConfigured() ? 'whatsapp' : null,
        ]));
    }

    /** The visitor's gate pass: the most recent, if the host re-registered them. */
    public function gatePass(): HasOne
    {
        return $this->hasOne(GatePass::class)->latestOfMany();
    }

    public function sendPassNotification(array $channels = ['email']): void
    {
        SendVisitorPassNotification::dispatch($this, $channels);
    }

    public function resendPassNotification(array $channels = ['email']): void
    {
        // Generate new QR code and resend
        $this->update(['qr_code_path' => null]);
        SendVisitorPassNotification::dispatch($this, $channels);
    }

    /**
     * Get the indexable data array for Scout.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'contact' => (string) $this->contact,
            'vehicle' => (string) $this->vehicle,
            'id_number' => (string) $this->id_number,
            'type' => (string) $this->type,
            'homeowner_name' => (string) $this->homeowner_name,
        ];
    }
}
