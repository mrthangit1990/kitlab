<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ($this->statements() as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->statements()) as $statement) {
            preg_match('/^CREATE TABLE `([a-z_]+)`/', $statement, $matches);
            DB::unprepared('DROP TABLE IF EXISTS `'.$matches[1].'`');
        }
    }

    private function statements(): array
    {
        return array_values(array_filter(array_map('trim', explode(';', file_get_contents(database_path('schema.sql'))))));
    }
};
