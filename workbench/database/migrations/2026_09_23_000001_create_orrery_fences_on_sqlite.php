<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Orrery fence table on the database connection [sqlite]
 * (written by php artisan orrery:install --fence). A retry or forget inserts
 * its fence row here in the same transaction as its effect on this
 * database, so a stale or concurrent actor loses on the key. The migration
 * creates the table only when it is missing, and never drops it.
 */
return new class extends Migration
{
    /** @var string */
    protected $connection = 'sqlite';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('orrery_fences')) {
            return;
        }

        $prefix = $schema->getConnection()->getTablePrefix();

        $schema->create('orrery_fences', function (Blueprint $table) use ($prefix): void {
            $table->char('operation_id', 32);
            // publish, delete or restore
            $table->string('slot', 8);
            // publish: published or cancelled; delete: deleted or delete_cancelled; restore: restored
            $table->string('state', 16);
            // publish: the published job id; restore: the restored record's new id
            $table->unsignedBigInteger('job_id')->nullable();
            // The operation's deadline in this database's clock.
            $table->decimal('deadline', 16, 6);
            // This database's clock.
            $table->decimal('created_at', 16, 6);

            $table->primary(['operation_id', 'slot'], $this->indexName($prefix, 'primary'));
            $table->index(['created_at', 'operation_id', 'slot'], $this->indexName($prefix, 'created'));
        });
    }

    public function down(): void
    {
        // The fence keeps the decisions of finished operations: dropping it could let a stale actor act again.
    }

    /**
     * The index names of Orrery's own schema: at most 63 bytes.
     */
    private function indexName(string $prefix, string $suffix): string
    {
        $name = $prefix.'orrery_fences_'.$suffix;

        if (strlen($name) <= 63) {
            return $name;
        }

        return mb_strcut($name, 0, 50, 'UTF-8').'_'.substr(hash('sha256', $name), 0, 12);
    }
};
