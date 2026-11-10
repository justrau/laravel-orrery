<?php

use Illuminate\Database\Migrations\Migration;
use Justrau\Orrery\Database\Schema\PackageSchema;

/*
 * The package tables of Spec B §10. This file is frozen from the tag v0.1.0 on: it only names
 * the package connection and calls PackageSchema, which holds the definitions, the schema
 * version and the install and removal policy (install(), uninstall()).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageSchema::migrationConnection();
    }

    public function up(): void
    {
        PackageSchema::install();
    }

    public function down(): void
    {
        PackageSchema::uninstall();
    }
};
