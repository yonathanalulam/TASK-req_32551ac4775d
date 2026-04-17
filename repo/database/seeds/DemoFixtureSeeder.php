<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Optional demo data: one venue, one piece of equipment, and a baseline rule pack with
 * a banned-domain and ad-link-density rule so parse endpoints produce meaningful flags.
 */
final class DemoFixtureSeeder extends AbstractSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->table('event_venues')->insert([[
            'key' => 'main_hall',
            'name' => 'Main Hall',
            'location_description' => 'Primary indoor venue',
            'capacity' => 500,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'key' => 'outdoor_track',
            'name' => 'Outdoor Track',
            'location_description' => 'All-weather 400m track',
            'capacity' => 200,
            'created_at' => $now,
            'updated_at' => $now,
        ]])->saveData();

        $this->table('event_equipment')->insert([[
            'key' => 'timing_beam_01',
            'name' => 'Photoelectric Timing Beam',
            'category' => 'timing',
            'description' => 'Single-lane photoelectric beam',
            'created_at' => $now,
            'updated_at' => $now,
        ]])->saveData();

        $this->table('rule_packs')->insert([[
            'key' => 'default_content_integrity',
            'description' => 'Default offline content integrity checks.',
            'created_at' => $now,
            'updated_at' => $now,
        ]])->saveData();
        $packId = (int) $this->fetchAll('SELECT id FROM rule_packs WHERE `key` = \'default_content_integrity\'')[0]['id'];

        $this->table('rule_pack_versions')->insert([[
            'rule_pack_id' => $packId,
            'version' => 1,
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]])->saveData();
        $versionId = (int) $this->fetchAll('SELECT id FROM rule_pack_versions WHERE rule_pack_id = ' . $packId . ' AND version = 1')[0]['id'];

        $this->table('rule_pack_rules')->insert([
            [
                'rule_pack_version_id' => $versionId,
                'rule_kind' => 'ad_link_density',
                'pattern' => null,
                'threshold' => 3.0,
                'severity' => 'warning',
                'description' => 'Flag when ad-link density exceeds 3/1000 chars.',
                'created_at' => $now,
            ],
            [
                'rule_pack_version_id' => $versionId,
                'rule_kind' => 'banned_domain',
                'pattern' => 'bad.example',
                'threshold' => null,
                'severity' => 'critical',
                'description' => 'Blocks preserved URLs pointing to bad.example.',
                'created_at' => $now,
            ],
        ])->saveData();
    }
}
