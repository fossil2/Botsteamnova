<?php
declare(strict_types=1);

class BotSpyAI
{
    /* =========================
     * CONFIG
     * ========================= */
    private const MIN_HANGAR     = 4;
    private const MIN_COMBUSTION = 3;
    private const MIN_SPY_TECH   = 2;

    private const MIN_SONDEN = 1;

    private const SHIP_SPY        = 210;
    private const SHIP_TRANSPORT  = 202;

    private const EXPEDITION_SHIPS = 4;
    private const EXPEDITION_PLANET = 16;

    /* =========================
     * ENTRY
     * ========================= */
    public static function run(int $botId): void
    {
        $db = Database::get();

        $USER = $db->selectSingle(
            'SELECT * FROM %%USERS%% WHERE id = :id AND is_bot = 1;',
            [':id' => $botId]
        );
        if (!$USER) {
            return;
        }

        $PLANET = $db->selectSingle(
            'SELECT * FROM %%PLANETS%%
             WHERE id_owner = :uid AND planet_type = 1
             LIMIT 1;',
            [':uid' => $botId]
        );
        if (!$PLANET) {
            return;
        }

        // 1️⃣ Spionage
        self::handleSpy($USER, $PLANET);

        // 2️⃣ Expedition (NEU)
        self::handleExpedition($USER, $PLANET);
    }

    /* =========================
     * 🛰 SPIONAGE
     * ========================= */
    private static function handleSpy(array $USER, array $PLANET): void
    {
        if (!empty($USER['bot_next_spy']) && $USER['bot_next_spy'] > TIMESTAMP) {
            return;
        }

        if (
            ($PLANET['hangar'] ?? 0) < self::MIN_HANGAR ||
            ($USER['combustion_tech'] ?? 0) < self::MIN_COMBUSTION ||
            ($USER['spy_tech'] ?? 0) < self::MIN_SPY_TECH
        ) {
            return;
        }

        if (($PLANET['spy_sonde'] ?? 0) < self::MIN_SONDEN) {
            self::queueShip($PLANET['id'], self::SHIP_SPY, 1);
            return;
        }

        $TARGET = self::getSpyTarget($USER, $PLANET);
        if (!$TARGET) {
            return;
        }

        self::sendSpyFleet($USER, $PLANET, $TARGET);

        Database::get()->update(
            'UPDATE %%USERS%% SET bot_next_spy = :t WHERE id = :id;',
            [
                ':t'  => TIMESTAMP + mt_rand(6 * 3600, 18 * 3600),
                ':id' => $USER['id'],
            ]
        );
    }

    /* =========================
     * 🚀 EXPEDITION (NEU)
     * ========================= */
    private static function handleExpedition(array $USER, array $PLANET): void
    {
        global $resource;
        
         if (!isset($USER['factor']) || !is_array($USER['factor'])) {
        $USER['factor'] = [];
    }

    if (!isset($USER['factor']['FleetSlots'])) {
        $USER['factor']['FleetSlots'] = 0;
    }

    if (!isset($USER['factor']['SpeedFleet'])) {
        $USER['factor']['SpeedFleet'] = 0;
    }

    if (!isset($USER['factor']['FlyTime'])) {
        $USER['factor']['FlyTime'] = 0;
    }

        // Astro ≥ 1
        if (empty($USER[$resource[124]]) || $USER[$resource[124]] < 1) {
            return;
        }

        // 1x pro Tag
        if (!empty($USER['bot_next_expedition']) && $USER['bot_next_expedition'] > TIMESTAMP) {
            return;
        }

        // Transporter bauen falls nötig
        if (($PLANET['small_ship_cargo'] ?? 0) < self::EXPEDITION_SHIPS) {
            self::queueShip(
                (int)$PLANET['id'],
                self::SHIP_TRANSPORT,
                self::EXPEDITION_SHIPS - (int)$PLANET['small_ship_cargo']
            );
            return;
        }

        // Freier Slot?
        if (FleetFunctions::GetCurrentFleets($USER['id']) >= FleetFunctions::GetMaxFleetSlots($USER)) {
            return;
        }

        require_once ROOT_PATH . 'includes/classes/class.FleetFunctions.php';

        $fleetArray = [
            self::SHIP_TRANSPORT => self::EXPEDITION_SHIPS
        ];

        $distance = FleetFunctions::GetTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$PLANET['galaxy'], $PLANET['system'], self::EXPEDITION_PLANET]
        );

        $speed = FleetFunctions::GetFleetMaxSpeed($fleetArray, $USER);

        $duration = FleetFunctions::GetMissionDuration(
            10,
            $speed,
            $distance,
            FleetFunctions::GetGameSpeedFactor(),
            $USER
        );

        $start = TIMESTAMP;
        $end   = $start + $duration;

        FleetFunctions::sendFleet(
            $fleetArray,
            15, // Expedition
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            1,
            0,
            0,
            $PLANET['galaxy'],
            $PLANET['system'],
            self::EXPEDITION_PLANET,
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $start,
            0,
            $end
        );

        // Cooldown: 1x täglich
        Database::get()->update(
            'UPDATE %%USERS%% SET bot_next_expedition = :t WHERE id = :id;',
            [
                ':t'  => TIMESTAMP + 86400,
                ':id' => $USER['id'],
            ]
        );
    }

    /* =========================
     * FLEETS
     * ========================= */
    private static function sendSpyFleet(array $USER, array $PLANET, array $TARGET): void
    {
        require_once ROOT_PATH . 'includes/classes/class.FleetFunctions.php';

        $fleetArray = [self::SHIP_SPY => 1];

        $distance = FleetFunctions::GetTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$TARGET['galaxy'], $TARGET['system'], $TARGET['planet']]
        );

        $speed = FleetFunctions::GetFleetMaxSpeed($fleetArray, $USER);

        $duration = FleetFunctions::GetMissionDuration(
            10,
            $speed,
            $distance,
            FleetFunctions::GetGameSpeedFactor(),
            $USER
        );

        FleetFunctions::sendFleet(
            $fleetArray,
            6,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            1,
            $TARGET['id_owner'],
            $TARGET['id'],
            $TARGET['galaxy'],
            $TARGET['system'],
            $TARGET['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            TIMESTAMP,
            0,
            TIMESTAMP + $duration
        );
    }

    /* =========================
     * HELFER
     * ========================= */
    private static function queueShip(int $planetId, int $shipId, int $amount): void
    {
        Database::get()->update(
            'UPDATE %%PLANETS%% SET b_hangar_id = :q WHERE id = :id;',
            [
                ':q'  => serialize([[$shipId, (float)$amount]]),
                ':id' => $planetId,
            ]
        );
    }

    private static function getSpyTarget(array $USER, array $PLANET): ?array
    {
        $row = Database::get()->selectSingle(
            'SELECT p.*
             FROM %%PLANETS%% p
             JOIN %%USERS%% u ON u.id = p.id_owner
             WHERE u.is_bot = 0
               AND p.id_owner != :me
               AND p.planet_type = 1
               AND p.galaxy = :gal
               AND p.system BETWEEN :s1 AND :s2
             ORDER BY RAND()
             LIMIT 1;',
            [
                ':me'  => $USER['id'],
                ':gal' => $PLANET['galaxy'],
                ':s1'  => max(1, $PLANET['system'] - 50),
                ':s2'  => min(499, $PLANET['system'] + 50),
            ]
        );

        return is_array($row) ? $row : null;
    }
}
