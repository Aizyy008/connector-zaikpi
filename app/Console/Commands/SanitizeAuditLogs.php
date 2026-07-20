<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off / repeatable cleanup: redacts any sensitive values (password hashes,
 * tokens, API keys, secrets, encrypted blobs) that may exist in older
 * `audit_logs.changes` rows written before the scrubbing safeguard was added.
 *
 * New records are already scrubbed at write time by AuditLog::record(); this
 * sanitizes the historical rows. Run `--dry-run` first to preview.
 */
class SanitizeAuditLogs extends Command
{
    protected $signature = 'audit:sanitize {--dry-run : Report what would change without writing}';

    protected $description = 'Redact secrets/password hashes from existing audit_logs records.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $redacted = 0;

        AuditLog::query()->whereNotNull('changes')->orderBy('id')->chunkById(200, function ($rows) use (&$scanned, &$redacted, $dryRun) {
            foreach ($rows as $row) {
                $scanned++;
                $original = $row->changes;

                if (! is_array($original)) {
                    continue;
                }

                $clean = AuditLog::scrub($original);

                if ($clean !== $original) {
                    $redacted++;
                    $this->line(($dryRun ? '[dry-run] would redact' : 'redacted').' audit #'.$row->id.' ('.$row->action.')');

                    if (! $dryRun) {
                        DB::table('audit_logs')->where('id', $row->id)->update(['changes' => json_encode($clean)]);
                    }
                }
            }
        });

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Scanned {$scanned} record(s); ".($dryRun ? 'would redact' : 'redacted')." {$redacted}.");

        if (! $dryRun && $redacted > 0) {
            $this->comment('Existing audit records sanitized. New records are scrubbed automatically at write time.');
        }

        return self::SUCCESS;
    }
}
