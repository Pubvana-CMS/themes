<?php

declare(strict_types=1);

namespace Pubvana\Themes\Database\Migrations;

use Enlivenapp\Migrations\Services\Migration;

class CreateBlockPlacementValuesTable extends Migration
{
    public function up(): void
    {
        $this->table('block_placement_values')
            ->addColumn('id', 'primary', [])
            ->addColumn('placement_id', 'integer', ['unsigned' => true])
            ->addColumn('field_key', 'string', ['length' => 150])
            ->addColumn('field_value', 'text', ['nullable' => true, 'default' => null])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addIndex(['placement_id'])
            ->addIndex(['placement_id', 'field_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('block_placement_values')->drop();
    }
}
