<?php

namespace App\Console\Commands;

use App\Models\Simcard;
use App\Services\Esim\SimcardUserReferenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillSimcardUserReferences extends Command
{
    protected $signature = 'simcards:backfill-user-references
        {--commit : Persist the generated references}
        {--clear-raw : Clear legacy raw user_id values after a successful conversion}
        {--include-user-one : Include legacy user_id=1 rows (unsafe unless independently verified)}';

    protected $description = 'Dry-run or backfill versioned user_ref values from legacy raw simcard user_id data.';

    public function handle(SimcardUserReferenceService $references): int
    {
        $commit = (bool) $this->option('commit');
        $clearRaw = (bool) $this->option('clear-raw');
        $includeUserOne = (bool) $this->option('include-user-one');

        if ($clearRaw && ! $commit) {
            $this->error('--clear-raw requires --commit.');

            return self::INVALID;
        }

        $summary = [
            'legacy_rows' => 0,
            'already_converted' => 0,
            'eligible' => 0,
            'skipped_user_one' => 0,
            'converted' => 0,
            'raw_ids_cleared' => 0,
        ];

        Simcard::query()
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunk(250, function ($simcards) use (
                &$summary,
                $references,
                $commit,
                $clearRaw,
                $includeUserOne,
            ): void {
                foreach ($simcards as $simcard) {
                    $summary['legacy_rows']++;

                    $legacyUserId = (int) $simcard->user_id;

                    if ($legacyUserId === 1 && ! $includeUserOne) {
                        $summary['skipped_user_one']++;
                        continue;
                    }

                    if ($simcard->user_ref !== null) {
                        $summary['already_converted']++;

                        if (
                            $commit
                            && $clearRaw
                            && $legacyUserId > 0
                            && $references->matches(
                                $simcard->user_ref,
                                $legacyUserId,
                                $simcard->user_ref_version,
                            )
                        ) {
                            Simcard::query()
                                ->whereKey($simcard->getKey())
                                ->update(['user_id' => null]);

                            $summary['raw_ids_cleared']++;
                        }

                        continue;
                    }

                    if ($legacyUserId < 1) {
                        continue;
                    }

                    $summary['eligible']++;

                    if (! $commit) {
                        continue;
                    }

                    DB::transaction(function () use (
                        $simcard,
                        $legacyUserId,
                        $references,
                        $clearRaw,
                        &$summary,
                    ): void {
                        $locked = Simcard::query()
                            ->whereKey($simcard->getKey())
                            ->lockForUpdate()
                            ->first();

                        if (! $locked || $locked->user_ref !== null) {
                            return;
                        }

                        $version = $references->currentVersion();
                        $locked->user_ref = $references->derive($legacyUserId, $version);
                        $locked->user_ref_version = $version;
                        $locked->user_linked_at = now();
                        $locked->user_link_source = 'account_migration';

                        if ($clearRaw) {
                            $locked->user_id = null;
                            $summary['raw_ids_cleared']++;
                        }

                        $locked->save();
                        $summary['converted']++;
                    });
                }
            });

        $this->table(
            ['Metric', 'Count'],
            collect($summary)
                ->map(fn (int $count, string $metric): array => [$metric, $count])
                ->values()
                ->all(),
        );

        if (! $commit) {
            $this->warn('Dry run only. Re-run with --commit after reviewing the counts.');
        }

        if ($summary['skipped_user_one'] > 0) {
            $this->warn('Legacy user_id=1 rows were skipped and remain unclaimed by default.');
        }

        return self::SUCCESS;
    }
}
