<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NodeAdminGroupService
{
    public static function next(?string $group, int $count = 1, string $scope = 'node'): int
    {
        $name = trim((string) $group);
        return DB::transaction(function () use ($name, $count, $scope) {
            DB::table('dboard_node_scope_sequences')->insertOrIgnore(['scope' => $scope, 'group_name' => $name, 'last_number' => 0]);
            $sequence = DB::table('dboard_node_scope_sequences')->where('scope', $scope)->where('group_name', $name);
            $last = (int) $sequence->lockForUpdate()->value('last_number');
            $nodes = DB::table('v2_server')->where('admin_scope', $scope);
            $name === '' ? $nodes->whereNull('admin_group') : $nodes->where('admin_group', $name);
            $last = max($last, (int) $nodes->max('admin_group_number'));
            $sequence->update(['last_number' => $last + $count]);
            return $last + 1;
        }, 5);
    }

    public static function move(array $ids, ?string $group, string $scope = 'node'): void
    {
        if (!$ids) return;
        $name = trim((string) $group);
        DB::transaction(function () use ($ids, $name, $scope) {
            $nodes = DB::table('v2_server')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
                ->get(['id', 'admin_group', 'admin_group_number', 'admin_scope'])
                ->filter(fn ($node) => trim((string) $node->admin_group) !== $name || $node->admin_scope !== $scope || !$node->admin_group_number);
            if ($nodes->isEmpty()) return;
            $number = self::next($name, $nodes->count(), $scope);
            foreach ($nodes as $node) {
                DB::table('v2_server')->where('id', $node->id)->update([
                    'admin_scope' => $scope,
                    'admin_group' => $name === '' ? null : $name,
                    'admin_group_number' => $number++,
                ]);
            }
        }, 5);
    }

    public static function rename(string $old, string $new, string $scope = 'node'): void
    {
        if ($old === $new) return;
        $last = (int) DB::table('dboard_node_scope_sequences')->where('scope', $scope)->where('group_name', $old)->value('last_number');
        $last = max($last, (int) DB::table('dboard_node_scope_sequences')->where('scope', $scope)->where('group_name', $new)->value('last_number'));
        DB::table('dboard_node_scope_sequences')->updateOrInsert(['scope' => $scope, 'group_name' => $new], ['last_number' => $last]);
        DB::table('dboard_node_scope_sequences')->where('scope', $scope)->where('group_name', $old)->delete();
    }
}
