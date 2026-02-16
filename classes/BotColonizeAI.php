<?php
declare(strict_types=1);

/**
 * BotColonizeAI (FINAL)
 *
 * - Kolonisiert nur, wenn Astrophysik genug Slots erlaubt
 * - Baut max. 1 Kolonieschiff
 * - Nutzt FleetFunctions (wie Spieler!)
 */

class BotColonizeAI
{
    /* =========================
     * KONFIG
     * ========================= */
    private const SHIP_COLONIZER     = 208;
    private const MISSION_COLONIZE   = 7;

    private const MIN_HANGAR         = 4;
    private const MIN_IMPULSE        = 3;

    private const PLANET_MIN         = 4;
    private const PLANET_MAX         = 12;
    private const SYSTEM_RANGE       = 50;

    private const COOLDOWN_MIN       = 6 * 3600;
    private const COOLDOWN_MAX       = 18 * 3600;

    /* =========================
     * ENTRY
     * ========================= */
    public static function run(int $botId): void
    {
        $db = Database::get();

        /* 👤 USER */
        $USER = $db->selectSingle(
            'SELECT * FROM %%USERS%% WHERE id = :id AND is_bot = 1;',
            [':id' => $botId]
        );
        if (!$USER) {
            return;
        }

        /* ⏱ Cooldown */
        if (!empty($USER['bot_next_colonize']) && (int)$USER['bot_next_colonize'] > TIMESTAMP) {
            return;
        }

        /* 🪐 Hauptplanet */
        $PLANET = $db->selectSingle(
            'SELECT * FROM %%PLANETS%%
             WHERE id_owner = :uid AND planet_type = 1
             ORDER BY id ASC
             LIMIT 1;',
            [':uid' => $botId]
        );
        if (!$PLANET) {
            return;
        }

        /* 🧮 Astro / Planeten-Limit (ENGINE-LOGIK)
         * maxPlanets = 1 + astroLevel
         * Astro 0 => nur HP (1 Planet) => KEINE Kolonie möglich
         */
        $astro        = self::getAstroLevel($USER);
        $ownedPlanets = self::countPlanets($botId);
        $maxPlanets   = 1 + $astro;

        // 🔒 Astro fehlt => keine Kolonie möglich
        if ($ownedPlanets >= $maxPlanets) {
            self::setCooldown($botId);
            return;
        }

        /* ✅ Voraussetzungen */
        if (
            (int)($PLANET['hangar'] ?? 0) < self::MIN_HANGAR ||
            (int)($USER['impulse_motor_tech'] ?? 0) < self::MIN_IMPULSE
        ) {
            self::setCooldown($botId);
            return;
        }

        /* 🚫 Bereits Koloflotte unterwegs? */
        if (self::hasColonizeFleet($botId, (int)$USER['universe'])) {
            return;
        }

        /* 🚀 Kolonieschiff vorhanden? */
        if ((int)($PLANET['colonizer'] ?? 0) < 1) {
            if (!empty($PLANET['b_hangar']) && (int)$PLANET['b_hangar'] > TIMESTAMP) {
                return;
            }

            self::queueColonizer((int)$PLANET['id']);
            self::setCooldown($botId);
            return;
        }

        /* 🎯 Ziel suchen */
        $target = self::findTarget($PLANET);
        if (!$target) {
            self::setCooldown($botId);
            return;
        }

        /* 🚀 Mission senden */
        self::sendColonizeFleet($USER, $PLANET, $target);
        self::setCooldown($botId);
    }

    /* =========================
     * FLEET SEND (SPIELER-KONFORM)
     * ========================= */
    private static function sendColonizeFleet(array $USER, array $PLANET, array $target): void
    {
        require_once ROOT_PATH . 'includes/vars.php';
        require_once ROOT_PATH . 'includes/classes/class.FleetFunctions.php';

        if (!isset($USER['factor']) && function_exists('getFactors')) {
            $USER['factor'] = getFactors($USER, 'basic', TIMESTAMP);
        }

        $fleetArray = [self::SHIP_COLONIZER => 1];

        $distance = FleetFunctions::GetTargetDistance(
            [(int)$PLANET['galaxy'], (int)$PLANET['system'], (int)$PLANET['planet']],
            [(int)$target['galaxy'], (int)$target['system'], (int)$target['planet']]
        );

        $speed = FleetFunctions::GetFleetMaxSpeed($fleetArray, $USER);

        $duration = (int) FleetFunctions::GetMissionDuration(
            10,
            $speed,
            $distance,
            FleetFunctions::GetGameSpeedFactor(),
            $USER
        );

        $startTime = TIMESTAMP;
        $arrival   = $startTime + $duration;
        $return    = $arrival + $duration;

        $consumption = (int) FleetFunctions::GetFleetConsumption(
            $fleetArray,
            $duration,
            $distance,
            $USER,
            FleetFunctions::GetGameSpeedFactor()
        );

        // ⚠️ Ziel ist leerer Slot => targetOwner=0, targetPlanetID=0 (wie Spieler-Insert)
        FleetFunctions::sendFleet(
            $fleetArray,
            self::MISSION_COLONIZE,

            (int)$USER['id'],
            (int)$PLANET['id'],
            (int)$PLANET['galaxy'],
            (int)$PLANET['system'],
            (int)$PLANET['planet'],
            1,

            0,                      // fleet_target_owner
            0,                      // fleet_end_id
            (int)$target['galaxy'],
            (int)$target['system'],
            (int)$target['planet'],
            1,

            [901 => 0, 902 => 0, 903 => 0],
            $arrival,               // fleet_start_time (ankunft)
            $arrival,               // fleet_end_stay (bei Kolo = ankunft ok)
            $return,                // fleet_end_time (rueckflug)
            0,
            0,
            0,
            $consumption
        );
    }

    /* =========================
     * HILFSFUNKTIONEN
     * ========================= */
    private static function queueColonizer(int $planetId): void
    {
        Database::get()->update(
            'UPDATE %%PLANETS%% SET b_hangar_id = :q WHERE id = :id;',
            [
                ':q'  => serialize([[self::SHIP_COLONIZER, 1.0]]),
                ':id' => $planetId,
            ]
        );
    }

    private static function hasColonizeFleet(int $botId, int $universe): bool
    {
        return (bool) Database::get()->selectSingle(
            'SELECT fleet_id FROM %%FLEETS%%
             WHERE fleet_owner = :uid
               AND fleet_mission = :m
               AND fleet_universe = :u
             LIMIT 1;',
            [
                ':uid' => $botId,
                ':m'   => self::MISSION_COLONIZE,
                ':u'   => $universe,
            ]
        );
    }

    private static function findTarget(array $PLANET): ?array
    {
        $gal = (int)$PLANET['galaxy'];
        $min = max(1, (int)$PLANET['system'] - self::SYSTEM_RANGE);
        $max = min(499, (int)$PLANET['system'] + self::SYSTEM_RANGE);

        for ($i = 0; $i < 40; $i++) {
            $sys = mt_rand($min, $max);
            $pl  = mt_rand(self::PLANET_MIN, self::PLANET_MAX);

            $exists = Database::get()->selectSingle(
                'SELECT id FROM %%PLANETS%%
                 WHERE galaxy = :g AND system = :s AND planet = :p AND planet_type = 1
                 LIMIT 1;',
                [':g' => $gal, ':s' => $sys, ':p' => $pl]
            );

            if (!$exists) {
                return ['galaxy' => $gal, 'system' => $sys, 'planet' => $pl];
            }
        }

        return null;
    }

    private static function countPlanets(int $botId): int
    {
        $row = Database::get()->selectSingle(
            'SELECT COUNT(*) AS c FROM %%PLANETS%%
             WHERE id_owner = :id AND planet_type = 1;',
            [':id' => $botId]
        );
        return (int)($row['c'] ?? 0);
    }

    private static function getAstroLevel(array $USER): int
    {
        global $resource;
        return (isset($resource[124], $USER[$resource[124]])) ? (int)$USER[$resource[124]] : 0;
    }

    private static function setCooldown(int $botId): void
    {
        Database::get()->update(
            'UPDATE %%USERS%% SET bot_next_colonize = :t WHERE id = :id;',
            [
                ':t'  => TIMESTAMP + mt_rand(self::COOLDOWN_MIN, self::COOLDOWN_MAX),
                ':id' => $botId,
            ]
        );
    }
}
