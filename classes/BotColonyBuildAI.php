/*
 * Projekt: https://github.com/fossil2/BotsteamNova.git
 * Autor: Fossil
 * Datum: 2026-02-16
 */


<?php
declare(strict_types=1);

class BotColonyBuildAI
{
    /* =========================
     * ENTRY
     * ========================= */
    public static function run(int $userId): void
    {
        $db = Database::get();

        self::log([
            'action' => 'COLONY_RUN',
            'uid'    => $userId,
        ]);

        /* USER */
        $USER = $db->selectSingle(
            "SELECT * FROM " . DB_PREFIX . "users
             WHERE id = :uid AND is_bot = 1",
            [':uid' => $userId]
        );

        if (!$USER) {
            return;
        }

        /* KOLONIEN LADEN */
        $planets = $db->select(
            "SELECT *
             FROM " . DB_PREFIX . "planets
             WHERE id_owner = :uid
               AND planet_type = 1
             ORDER BY id ASC",
            [':uid' => $userId]
        );

        if (empty($planets)) {
            return;
        }

        $mainId = self::getMainPlanetId($userId);

        foreach ($planets as $PLANET) {

            // Hauptplanet überspringen
            if ((int)$PLANET['id'] === $mainId) {
                self::log([
                    'action' => 'COLONY_SKIP_MAIN',
                    'pid'    => $PLANET['id'],
                ]);
                continue;
            }

            // Menschliche Faulheit 😴 (15%)
            if (mt_rand(1, 100) <= 15) {
                self::log([
                    'action' => 'COLONY_SKIP_LAZY',
                    'pid'    => $PLANET['id'],
                ]);
                continue;
            }

            // Bauqueue aktiv?
            if ((int)$PLANET['b_building'] > time()) {
                continue;
            }

            self::log([
                'action' => 'COLONY_CHECK',
                'uid'    => $userId,
                'pid'    => $PLANET['id'],
                'metal'  => (float)$PLANET['metal'],
                'crys'   => (float)$PLANET['crystal'],
                'deut'   => (float)$PLANET['deuterium'],
                'energy' => (int)$PLANET['energy'],
            ]);

            if (self::tryBuild($PLANET)) {
                return; // ⛔ exakt EIN Bau
            }
        }
    }

    /* =========================
     * TRY BUILD
     * ========================= */
    private static function tryBuild(array $p): bool
    {
        $nextMineLevel = max(
            (int)$p['metal_mine'],
            (int)$p['crystal_mine'],
            (int)$p['deuterium_sintetizer']
        ) + 1;

        // 🔧 Optimierte BuildOrder
        $buildOrder = [
            // 🔴 Überleben
            self::ID_SOLAR_PLANT,
            self::ID_METAL_MINE,
            self::ID_CRYSTAL_MINE,

            // 🟡 Wachstum
            self::ID_DEUTERIUM_SYNTH,
            self::ID_METAL_STORE,
            self::ID_CRYSTAL_STORE,

            // 🟢 Komfort
            self::ID_ROBOT_FACTORY,
            self::ID_DEUTERIUM_STORE,
            self::ID_HANGAR,
        ];

        // Menschliche Unordnung 😅
        shuffle($buildOrder);

        foreach ($buildOrder as $elementId) {

            if (!self::shouldBuild($p, $elementId, $nextMineLevel)) {
                continue;
            }

            if (self::startBuild($p, $elementId)) {
                return true;
            }
        }

        // 🧯 FALLBACK: irgendwas Kleines bauen
        if ($p['metal'] > 300 && $p['crystal'] > 150) {
            self::log([
                'action' => 'COLONY_FALLBACK',
                'pid'    => $p['id'],
                'build'  => self::ID_METAL_MINE,
            ]);
            return self::startBuild($p, self::ID_METAL_MINE);
        }

        self::log([
            'action' => 'COLONY_NO_CANDIDATE',
            'pid'    => $p['id'],
        ]);

        return false;
    }

    /* =========================
     * SHOULD BUILD ?
     * ========================= */
    private static function shouldBuild(array $p, int $id, int $nextMineLevel): bool
    {
        if ((int)$p['energy'] < 0 && $id !== self::ID_SOLAR_PLANT) {
            return false;
        }

        return match ($id) {

            self::ID_SOLAR_PLANT =>
                (int)$p['solar_plant'] === 0 ||
                self::needsMoreEnergy($p, $nextMineLevel),

            self::ID_METAL_MINE      => $p['metal_mine'] < 25,
            self::ID_CRYSTAL_MINE    => $p['crystal_mine'] < 20,
            self::ID_DEUTERIUM_SYNTH => $p['deuterium_sintetizer'] < 15,

            self::ID_METAL_STORE     => $p['metal_store'] < 5,
            self::ID_CRYSTAL_STORE   => $p['crystal_store'] < 4,
            self::ID_DEUTERIUM_STORE => $p['deuterium_store'] < 3,

            self::ID_ROBOT_FACTORY   => $p['robot_factory'] < 6,
            self::ID_HANGAR          => $p['hangar'] < 6,

            default => false,
        };
    }

    /* =========================
     * ENERGY CHECK
     * ========================= */
    private static function needsMoreEnergy(array $p, int $nextMineLevel): bool
    {
        $solar = max(0, (int)$p['solar_plant']) * 55;

        $need =
            ((int)$p['metal_mine'] * 10) +
            ((int)$p['crystal_mine'] * 15) +
            ((int)$p['deuterium_sintetizer'] * 25);

        $future = $need + ($nextMineLevel * 18);

        return $solar < ($future * 1.30);
    }

    /* =========================
     * START BUILD
     * ========================= */
    private static function startBuild(array $planet, int $elementId): bool
    {
        global $resource;

        if (!isset($resource[$elementId])) {
            return false;
        }

        $field  = $resource[$elementId];
        $from   = (int)$planet[$field];
        $to     = $from + 1;

        $cost = self::getBuildCost($elementId, $to);

        // Menschlicher Ressourcencheck (90%)
        if (
    $planet['metal']     < $cost['metal'] ||
    $planet['crystal']   < $cost['crystal'] ||
    $planet['deuterium'] < $cost['deuterium']
) {
    self::log([
        'action' => 'COLONY_NO_RES',
        'pid'    => $planet['id'],
        'build'  => $elementId,
        'have'   => [
            'metal' => (int)$planet['metal'],
            'crys'  => (int)$planet['crystal'],
            'deut'  => (int)$planet['deuterium'],
        ],
        'need'   => $cost,
    ]);
    return false;
}

        $now = time();
        $end = $now + 10;

        $queue = serialize([
            [$elementId, $to, [], (float)$end, 'build']
        ]);

        Database::get()->update(
            "UPDATE " . DB_PREFIX . "planets SET
                metal         = metal - :m,
                crystal       = crystal - :c,
                deuterium     = deuterium - :d,
                b_building    = :end,
                b_building_id = :queue
             WHERE id = :pid",
            [
                ':m'     => $cost['metal'],
                ':c'     => $cost['crystal'],
                ':d'     => $cost['deuterium'],
                ':end'   => $end,
                ':queue' => $queue,
                ':pid'   => $planet['id'],
            ]
        );

        self::log([
            'action' => 'COLONY_BUILD_START',
            'pid'    => $planet['id'],
            'build'  => $elementId,
            'from'   => $from,
            'to'     => $to,
            'cost'   => $cost,
        ]);

        return true;
    }

    /* =========================
     * BUILD COST
     * ========================= */
    private static function getBuildCost(int $id, int $level): array
    {
        $v = Database::get()->selectSingle(
            "SELECT factor, cost901, cost902, cost903
             FROM " . DB_PREFIX . "vars
             WHERE elementID = :id",
            [':id' => $id]
        );

        if (!$v) {
            return ['metal'=>0,'crystal'=>0,'deuterium'=>0];
        }

        $f = (float)$v['factor'];

        return [
            'metal'     => (int)floor($v['cost901'] * pow($f, $level - 1)),
            'crystal'   => (int)floor($v['cost902'] * pow($f, $level - 1)),
            'deuterium' => (int)floor($v['cost903'] * pow($f, $level - 1)),
        ];
    }

    /* =========================
     * MAIN PLANET ID
     * ========================= */
    private static function getMainPlanetId(int $uid): int
    {
        $row = Database::get()->selectSingle(
            "SELECT id FROM " . DB_PREFIX . "planets
             WHERE id_owner = :uid AND planet_type = 1
             ORDER BY id ASC LIMIT 1",
            [':uid' => $uid]
        );

        return (int)($row['id'] ?? 0);
    }

    /* =========================
     * LOGGING
     * ========================= */
    private static function log(array $data): void
    {
        $dir = ROOT_PATH . 'includes/ai_log/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data['time']     = time();
        $data['datetime'] = date('Y-m-d H:i:s');

        file_put_contents(
            $dir . 'bot_colony.json',
            json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }

    /* =========================
     * IDS
     * ========================= */
    private const ID_METAL_MINE      = 1;
    private const ID_CRYSTAL_MINE    = 2;
    private const ID_DEUTERIUM_SYNTH = 3;
    private const ID_SOLAR_PLANT     = 4;
    private const ID_ROBOT_FACTORY   = 14;
    private const ID_HANGAR          = 21;
    private const ID_METAL_STORE     = 22;
    private const ID_CRYSTAL_STORE   = 23;
    private const ID_DEUTERIUM_STORE = 24;
}
