/*
 * Projekt: https://github.com/fossil2/BotsteamNova.git
 * Autor: Fossil
 * Datum: 2026-02-16
 */

<?php
declare(strict_types=1);

class BotBuildAI
{
    /* =========================
     * ENTRY POINT
     * ========================= */
    public static function run(int $userId): void
    {
        self::log([
            'action' => 'RUN_CALLED',
            'userId' => $userId,
        ]);

        $db = Database::get();

        /* USER */
        $USER = $db->selectSingle(
            "SELECT id FROM " . DB_PREFIX . "users WHERE id = :uid",
            [':uid' => $userId]
        );

        if (!$USER) {
            self::log(['action' => 'NO_USER']);
            return;
        }

        /* PLANET */
        $PLANET = self::getMainPlanet($userId);

        if (!$PLANET) {
            self::log(['action' => 'NO_PLANET']);
            return;
        }

        /* Queue aktiv? → abbrechen */
        if ((int)$PLANET['b_building'] > time()) {
            self::log([
                'action' => 'QUEUE_ACTIVE',
                'until'  => $PLANET['b_building']
            ]);
            return;
        }

        /* =========================
         * TRY-NEXT-POSSIBLE-BUILD
         * ========================= */

        $buildOrder = [

            // Energie immer zuerst prüfen
            self::ID_SOLAR_PLANT,

            // Minen Earlygame
            self::ID_METAL_MINE,
            self::ID_CRYSTAL_MINE,
            self::ID_DEUTERIUM_SYNTH,

            // Speicher passend zu Minen
            self::ID_METAL_STORE,
            self::ID_CRYSTAL_STORE,
            self::ID_DEUTERIUM_STORE,

            // Basis Infrastruktur
            self::ID_ROBOT_FACTORY,
            self::ID_HANGAR,

            // Midgame
            self::ID_ROBOT_FACTORY,
            self::ID_LABORATORY,
        ];

        foreach ($buildOrder as $elementId) {

            if (!self::shouldBuild($PLANET, $elementId)) {
                continue;
            }

            if (self::startBuild($PLANET, $elementId)) {
                return; // exakt EIN Bauvorgang
            }
        }

        self::log(['action' => 'NO_ACTION']);
    }


    /* =========================
     * SHOULD BUILD ?
     * ========================= */
    private static function shouldBuild(array $p, int $id): bool
    {
        $nextMineLevel = max(
            (int)$p['metal_mine'],
            (int)$p['crystal_mine'],
            (int)$p['deuterium_sintetizer']
        ) + 1;

        return match ($id) {

            // ⚡ Solar nur falls wirklich nötig
            self::ID_SOLAR_PLANT =>
                (int)$p['solar_plant'] === 0 ||
                self::needsMoreEnergy($p, $nextMineLevel),

            // Minen Soft-Caps
            self::ID_METAL_MINE      => $p['metal_mine'] < 22,
            self::ID_CRYSTAL_MINE    => $p['crystal_mine'] < 18,
            self::ID_DEUTERIUM_SYNTH => $p['deuterium_sintetizer'] < 12,

            // Speicher passend zum Level
            self::ID_METAL_STORE     => $p['metal_store'] < 5,
            self::ID_CRYSTAL_STORE   => $p['crystal_store'] < 4,
            self::ID_DEUTERIUM_STORE => $p['deuterium_store'] < 3,

            // Infrastruktur Progression
            self::ID_ROBOT_FACTORY   => $p['robot_factory'] < 4,
            self::ID_HANGAR          => $p['hangar'] < 6,
            self::ID_LABORATORY      => $p['laboratory'] < 5,

            default => false,
        };
    }


    /* =========================
     * ⚡ ENERGY CHECK
     * ========================= */
    private static function needsMoreEnergy(array $p, int $nextMineLevel): bool
{
    // Aktuelle Energieproduktion (vereinfachtes Solar-Modell)
    $solarOutput = max(0, (int)$p['solar_plant']) * 55;

    // Geschätzter Energieverbrauch der aktuellen Minen
    $currentNeed =
          ((int)$p['metal_mine']             * 10)
        + ((int)$p['crystal_mine']           * 15)
        + ((int)$p['deuterium_sintetizer']   * 25);

    // Verbrauch nach dem NÄCHSTEN Minenlevel (aggressivere Planung)
    $futureNeed = $currentNeed + ($nextMineLevel * 18);

    // Sicherheits-Puffer (Bots sollen IMMER +30% Reserve haben)
    $requiredWithBuffer = (int)ceil($futureNeed * 1.30);

    // Energie zu gering? → Solar bauen!
    return $solarOutput < $requiredWithBuffer;
}



    /* =========================
     * START BUILD
     * ========================= */
    private static function startBuild(array $planet, int $elementId): bool
    {
        global $resource;

        if (!isset($resource[$elementId])) {
            self::log(['action' => 'UNKNOWN_ELEMENT', 'id' => $elementId]);
            return false;
        }

        $field       = $resource[$elementId];
        $levelBefore = (int)($planet[$field] ?? 0);
        $targetLevel = $levelBefore + 1;

        $cost = self::getBuildCost($elementId, $targetLevel);

        // Ressourcenprüfung
        if (
            (float)$planet['metal']     < $cost['metal'] ||
            (float)$planet['crystal']   < $cost['crystal'] ||
            (float)$planet['deuterium'] < $cost['deuterium']
        ) {
            self::log([
                'action' => 'NOT_ENOUGH_RESOURCES',
                'field'  => $field,
                'level'  => $targetLevel,
                'need'   => $cost
            ]);
            return false;
        }

        $now = time();
        $end = $now + 10; // TEST-dauer — später realer Buildtime-Calc

        $queue = serialize([
            [$elementId, $targetLevel, [], (float)$end, 'build']
        ]);

        Database::get()->update(
            "UPDATE " . DB_PREFIX . "planets
             SET
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
            'action'     => 'BUILD_STARTED',
            'elementId'  => $elementId,
            'field'      => $field,
            'from'       => $levelBefore,
            'to'         => $targetLevel,
            'ends_at'    => $end,
        ]);

        return true;
    }


    /* =========================
     * COSTS FROM vars
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

        $factor = (float)$v['factor'];

        return [
            'metal'     => (int)floor($v['cost901'] * pow($factor, $level - 1)),
            'crystal'   => (int)floor($v['cost902'] * pow($factor, $level - 1)),
            'deuterium' => (int)floor($v['cost903'] * pow($factor, $level - 1)),
        ];
    }


    /* =========================
     * MAIN PLANET
     * ========================= */
    private static function getMainPlanet(int $uid): ?array
    {
        return Database::get()->selectSingle(
            "SELECT *
             FROM " . DB_PREFIX . "planets
             WHERE id_owner = :uid
               AND planet_type = 1
             LIMIT 1",
            [':uid' => $uid]
        );
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
            $dir . 'bot_actions.json',
            json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }


    /* =========================
     * BUILDING IDS
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
    private const ID_LABORATORY      = 31;
}
