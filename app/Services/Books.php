<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Books
{
    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['operation' => $message]);
        }
    }

    public static function vnd(string $amount, string $rate = '1'): int
    {
        return (int) bcadd(bcmul($amount, $rate, 8), '0.5', 0);
    }

    public static function portion(int $amount, int $take, int $total): int
    {
        return (int) bcdiv(bcmul((string) $amount, (string) $take, 0), (string) $total, 0);
    }

    public static function audit(int $user, string $action, string $entity, int $id, array $data): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $user, 'action' => $action, 'entity' => $entity,
            'entity_id' => $id, 'payload' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
