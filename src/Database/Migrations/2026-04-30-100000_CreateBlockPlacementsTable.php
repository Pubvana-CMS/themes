<?php

declare(strict_types=1);

namespace Pubvana\Themes\Database\Migrations;

use Enlivenapp\Migrations\Services\Migration;

class CreateBlockPlacementsTable extends Migration
{
    public function up(): void
    {
        $this->table('block_placements')
            ->addColumn('id', 'primary', [])
            ->addColumn('region_id', 'string', ['length' => 50])
            ->addColumn('block_key', 'string', ['length' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['nullable' => true, 'default' => null])
            ->addIndex(['region_id'])
            ->addIndex(['region_id', 'block_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('block_placements')->drop();
    }
}
