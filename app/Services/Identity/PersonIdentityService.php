<?php

namespace App\Services\Identity;

use App\Models\Person;
use App\Models\User;

/**
 * Person Identity Service.
 *
 * Guarantees one consistent identity for each individual across the estate:
 * Prevents duplicate identities across resident, renter, household, staff, and guest records.
 */
class PersonIdentityService
{
    /**
     * Resolve an existing Person or create a new one.
     *
     * Deduplication strategy:
     * 1. Exact user_id match
     * 2. Case-insensitive email match
     * 3. Normalized phone number match
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreate(array $attributes): Person
    {
        $userId = $attributes['user_id'] ?? null;
        $email = ! empty($attributes['email']) ? strtolower(trim($attributes['email'])) : null;
        $phone = ! empty($attributes['phone']) ? $this->normalizePhone($attributes['phone']) : null;
        $name = trim($attributes['name'] ?? '');

        // 1. By user_id
        if ($userId) {
            $person = Person::where('user_id', $userId)->first();
            if ($person) {
                return $this->enrichPerson($person, $attributes);
            }
        }

        // 2. By email
        if ($email) {
            $person = Person::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($person) {
                return $this->enrichPerson($person, $attributes);
            }
        }

        // 3. By normalized phone
        if ($phone && strlen($phone) >= 7) {
            $person = Person::all()->first(function (Person $p) use ($phone) {
                return $p->phone && $this->normalizePhone($p->phone) === $phone;
            });

            if ($person) {
                return $this->enrichPerson($person, $attributes);
            }
        }

        // 4. Create new Person
        return Person::create([
            'user_id' => $userId,
            'name' => $name ?: ($attributes['first_name'] ?? 'Unknown Person'),
            'first_name' => $attributes['first_name'] ?? null,
            'last_name' => $attributes['last_name'] ?? null,
            'email' => $email,
            'phone' => $attributes['phone'] ?? null,
            'id_type' => $attributes['id_type'] ?? null,
            'id_number' => $attributes['id_number'] ?? null,
            'id_expiry' => $attributes['id_expiry'] ?? null,
            'id_image_url' => $attributes['id_image_url'] ?? null,
            'photo_url' => $attributes['photo_url'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    /**
     * Resolve or create unified Person identity for an authenticated User.
     */
    public function findOrCreateForUser(User $user): Person
    {
        if ($user->relationLoaded('person') && $user->person) {
            return $user->person;
        }

        $existing = Person::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        // Check if a person exists with matching email or phone
        return $this->findOrCreate([
            'user_id' => $user->id,
            'name' => $user->display_name ?: $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'photo_url' => $user->avatar_url,
        ]);
    }

    /**
     * Fill in empty fields without overwriting established data.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function enrichPerson(Person $person, array $attributes): Person
    {
        $dirty = false;

        if (empty($person->user_id) && ! empty($attributes['user_id'])) {
            $person->user_id = $attributes['user_id'];
            $dirty = true;
        }

        if (empty($person->email) && ! empty($attributes['email'])) {
            $person->email = strtolower(trim($attributes['email']));
            $dirty = true;
        }

        if (empty($person->phone) && ! empty($attributes['phone'])) {
            $person->phone = $attributes['phone'];
            $dirty = true;
        }

        if (empty($person->id_number) && ! empty($attributes['id_number'])) {
            $person->id_number = $attributes['id_number'];
            $person->id_type = $attributes['id_type'] ?? $person->id_type;
            $person->id_expiry = $attributes['id_expiry'] ?? $person->id_expiry;
            $dirty = true;
        }

        if ($dirty) {
            $person->save();
        }

        return $person;
    }

    /**
     * Normalize a phone number to digits.
     */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d]/', '', $phone) ?? '';
    }
}
