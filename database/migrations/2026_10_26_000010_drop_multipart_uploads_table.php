<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Upload sessions replace `multipart_uploads`. A session still in flight there cannot continue under the new
 * flow, so the migration refuses to drop the table while one is open, unless the operator opts in by setting
 * `documentable.upgrade.force_drain` (or the DOCUMENTABLE_FORCE_DRAIN environment variable). Draining aborts each
 * provider upload first, best effort and logged, so no half-assembled object keeps accruing storage cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('multipart_uploads')) {
            return;
        }

        $open = DB::table('multipart_uploads')->where('expires_at', '>', now())->count();

        if ($open > 0 && ! $this->forced()) {
            throw new RuntimeException("{$open} multipart upload session(s) are still open. Let them finish or expire, then migrate again, or set DOCUMENTABLE_FORCE_DRAIN=true to abort them and drop the table.");
        }

        DB::table('multipart_uploads')->orderBy('created_at')->each(fn ($row) => $this->abort($row));

        Schema::dropIfExists('multipart_uploads');
    }

    public function down(): void
    {
        // Recreated by the original create migration if the package is rolled back that far.
    }

    private function forced(): bool
    {
        return (bool) config('documentable.upgrade.force_drain', false) || filter_var(env('DOCUMENTABLE_FORCE_DRAIN', false), FILTER_VALIDATE_BOOL);
    }

    private function abort(object $row): void
    {
        try {
            $type = DB::table('document_types')->where('id', $row->document_type_id)->first();

            if ($type === null) {
                return;
            }

            $driverName = (string) config("filesystems.disks.{$type->disk}.driver");
            $class = config("documentable.multipart.drivers.{$driverName}");

            if (! $class) {
                return;
            }

            /** @var MultipartUploadDriver $driver */
            $driver = app($class);
            $session = new UploadSession([
                'disk' => $type->disk,
                'path' => $row->path,
                'upload_id' => $row->upload_id,
            ]);
            $driver->abort($session);
        } catch (Throwable $e) {
            Log::warning("Documentable could not abort legacy multipart upload [{$row->upload_id}] while dropping multipart_uploads: {$e->getMessage()}");
        }
    }
};
