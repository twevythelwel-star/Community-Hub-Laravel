<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Per-year counters for human-facing numbers (CH-YYYY-…, LED-YYYY-…).
 *
 * The numbers were "latest issued + 1", read without a lock, on unique
 * columns: two payments at the same moment read the same latest number and
 * the second insert failed. next() increments the counter inside a
 * transaction with the row locked, so each call gets its own value.
 */
class IdSequence extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'scope';

    protected $fillable = ['scope', 'year', 'last_value'];

    public static function next(string $scope, int $year): int
    {
        return DB::transaction(function () use ($scope, $year): int {
            $row = DB::table('id_sequences')
                ->where('scope', $scope)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('id_sequences')->insertOrIgnore(['scope' => $scope, 'year' => $year, 'last_value' => 0]);
            }

            DB::table('id_sequences')->where('scope', $scope)->where('year', $year)->increment('last_value');

            return (int) DB::table('id_sequences')->where('scope', $scope)->where('year', $year)->value('last_value');
        });
    }
}
