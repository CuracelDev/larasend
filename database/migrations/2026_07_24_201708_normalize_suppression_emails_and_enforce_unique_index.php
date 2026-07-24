<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NORMALIZED_UNIQUE_INDEX = 'suppressions_project_normalized_email_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $migrationStartedAt = CarbonImmutable::now();

        DB::transaction(function () use ($migrationStartedAt): void {
            $this->consolidateCaseVariants($migrationStartedAt);

            DB::table('suppressions')->update([
                'email' => DB::raw('LOWER(TRIM(email))'),
            ]);
        });
        $this->addNormalizedUniqueIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'sqlsrv'], true)) {
            Schema::table('suppressions', function (Blueprint $table): void {
                $table->dropUnique(self::NORMALIZED_UNIQUE_INDEX);
                $table->dropColumn('normalized_email');
            });

            return;
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::NORMALIZED_UNIQUE_INDEX);

            return;
        }

        throw new RuntimeException("Unsupported database driver [{$driver}].");
    }

    private function consolidateCaseVariants(CarbonImmutable $migrationStartedAt): void
    {
        $duplicateGroups = DB::table('suppressions')
            ->select('project_id')
            ->selectRaw('LOWER(TRIM(email)) AS normalized_email')
            ->groupBy('project_id')
            ->groupByRaw('LOWER(TRIM(email))')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $suppressions = DB::table('suppressions')
                ->where('project_id', $group->project_id)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$group->normalized_email])
                ->get();

            $keeper = $suppressions
                ->sort(function (object $left, object $right) use ($migrationStartedAt): int {
                    return $this->priority($right, $migrationStartedAt)
                        <=> $this->priority($left, $migrationStartedAt);
                })
                ->first();

            if ($keeper === null) {
                continue;
            }

            $duplicateIds = $suppressions
                ->where('id', '!=', $keeper->id)
                ->pluck('id')
                ->all();

            DB::table('suppressions')->whereIn('id', $duplicateIds)->delete();
            DB::table('suppressions')->where('id', $keeper->id)->update([
                'email' => $group->normalized_email,
                'expires_at' => $this->strongestExpiration($suppressions, $migrationStartedAt),
                'updated_at' => $suppressions->max('updated_at'),
            ]);
        }
    }

    /**
     * Prefer active blockers, complaints, and rows carrying local ownership.
     * Remaining ties resolve by permanence, recency, then id.
     *
     * @return array<int, int>
     */
    private function priority(object $suppression, CarbonImmutable $migrationStartedAt): array
    {
        return [
            (int) $this->isActive($suppression, $migrationStartedAt),
            (int) ($suppression->reason === 'complaint' || $suppression->event_type === 'complaint'),
            (int) ($suppression->event_type !== 'provider_sync'),
            (int) ($suppression->email_id !== null),
            (int) ($suppression->source_id !== null),
            (int) ($suppression->expires_at === null),
            $this->timestamp($suppression->updated_at),
            (int) $suppression->id,
        ];
    }

    /**
     * @param  Collection<int, object>  $suppressions
     */
    private function strongestExpiration(Collection $suppressions, CarbonImmutable $migrationStartedAt): ?string
    {
        $activeSuppressions = $suppressions
            ->filter(fn (object $suppression): bool => $this->isActive($suppression, $migrationStartedAt));

        if ($activeSuppressions->contains(fn (object $suppression): bool => $suppression->expires_at === null)) {
            return null;
        }

        return $activeSuppressions->isNotEmpty()
            ? $activeSuppressions->max('expires_at')
            : $suppressions->max('expires_at');
    }

    private function isActive(object $suppression, CarbonImmutable $migrationStartedAt): bool
    {
        return $suppression->expires_at === null
            || CarbonImmutable::parse($suppression->expires_at)->isAfter($migrationStartedAt);
    }

    private function timestamp(mixed $value): int
    {
        return $value === null ? PHP_INT_MIN : CarbonImmutable::parse($value)->getTimestamp();
    }

    private function addNormalizedUniqueIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::NORMALIZED_UNIQUE_INDEX
                .' ON suppressions (project_id, LOWER(TRIM(email)))',
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('suppressions', function (Blueprint $table): void {
                $table->string('normalized_email')
                    ->storedAs('LOWER(TRIM(email))');
                $table->unique(['project_id', 'normalized_email'], self::NORMALIZED_UNIQUE_INDEX);
            });

            return;
        }

        if ($driver === 'sqlsrv') {
            Schema::table('suppressions', function (Blueprint $table): void {
                $table->computed('normalized_email', 'LOWER(TRIM([email]))')
                    ->persisted();
                $table->unique(['project_id', 'normalized_email'], self::NORMALIZED_UNIQUE_INDEX);
            });

            return;
        }

        throw new RuntimeException("Unsupported database driver [{$driver}].");
    }
};
