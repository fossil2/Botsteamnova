/*
 * Projekt: https://github.com/fossil2/BotsteamNova.git
 * Autor: Fossil
 * Datum: 2026-02-16
 */


<?php
declare(strict_types=1);

class BotDefenseAI
{
    /** DEFENSE IDs */
    private const ID_ROCKET_LAUNCHER = 401;
    private const ID_LIGHT_LASER     = 402;
    private const ID_HEAVY_LASER     = 403;

    /** MINDESTLEVEL */
    private const MIN_SHIPYARD_LEVEL = 2;

    public static function run(int $userId): void
    {
        $db = Database::get();

        /* USER */
        $USER = $db->selectSingle(
            'SELECT * FROM %%USERS%% WHERE id = :uid AND is_bot = 1;',
            [':uid' => $userId]
        );

        if (!$USER) {
            return;
        }

        /* USER POINTS (WICHTIG – FIX) */
        $scoreRow = $db->selectSingle(
            'SELECT total_points
             FROM %%USER_POINTS%%
             WHERE id_owner = :uid
               AND universe = :uni;',
            [
                ':uid' => $USER['id'],
                ':uni' => (int)($USER['universe'] ?? 1),
            ]
        );

        $USER['total_points'] = (int)($scoreRow['total_points'] ?? 0);

        /* HAUPTPLANET */
        $PLANET = $db->selectSingle(
            'SELECT *
             FROM %%PLANETS%%
             WHERE id_owner = :uid
               AND planet_type = 1
             LIMIT 1;',
            [':uid' => $userId]
        );

        if (!$PLANET) {
            return;
        }

        /* QUEUE AKTIV */
        if ((int)$PLANET['b_building'] > time()) {
            return;
        }

        /* WERFT */
        if ((int)$PLANET['hangar'] < self::MIN_SHIPYARD_LEVEL) {
            return;
        }

        /* BAUPRIORITÄT */
        $buildOrder = [
            self::ID_ROCKET_LAUNCHER,
            self::ID_LIGHT_LASER,
            self::ID_HEAVY_LASER,
        ];

        foreach ($buildOrder as $defId) {
            if (!self::shouldBuild($USER, $PLANET, $defId)) {
                continue;
            }

            if (self::buildDefense($PLANET, $defId)) {
                return; // exakt EIN Bau
            }
        }
    }

    /* =========================
     * ENTSCHEIDUNGSLOGIK
     * ========================= */
    private static function shouldBuild(array $USER, array $PLANET, int $id): bool
    {
        global $resource;

        $field = $resource[$id] ?? null;
        if ($field === null) {
            return false;
        }

        $count = (int)($PLANET[$field] ?? 0);

        // Dynamisches Limit Heavy Laser
        $maxHeavyLaser = 3;
        if ($USER['total_points'] >= 8000) {
            $maxHeavyLaser = 6;
        }
        if ($USER['total_points'] >= 15000) {
            $maxHeavyLaser = 10;
        }

        return match ($id) {

            // 🚀 Raketenwerfer
            self::ID_ROCKET_LAUNCHER =>
                $count < 20,

            // 🔫 Leichter Laser
            self::ID_LIGHT_LASER =>
                ($USER['laser_tech'] ?? 0) >= 1
                && $count < 10,

            // 🔥 Schwerer Laser (JETZT FUNKTIONIERT)
            self::ID_HEAVY_LASER =>
                $USER['total_points'] >= 4000
                && ($USER['energy_tech'] ?? 0) >= 3
                && ($USER['laser_tech'] ?? 0) >= 6
                && ($PLANET['hangar'] ?? 0) >= 4
                && $count < $maxHeavyLaser,

            default => false,
        };
    }

    /* =========================
     * BAU STARTEN
     * ========================= */
    private static function buildDefense(array $planet, int $elementId): bool
    {
        global $resource;

        if (!isset($resource[$elementId])) {
            return false;
        }

        $field   = $resource[$elementId];
        $current = (int)($planet[$field] ?? 0);
        $target  = $current + 1;

        // Kosten berechnen
        $cost = BuildFunctions::getElementPrice(
            [],          // USER nicht nötig
            $planet,
            $elementId,
            false,
            $target
        );

        // Ressourcen prüfen
        if (
            ($planet['metal']     ?? 0) < ($cost[901] ?? 0) ||
            ($planet['crystal']   ?? 0) < ($cost[902] ?? 0) ||
            ($planet['deuterium'] ?? 0) < ($cost[903] ?? 0)
        ) {
            return false;
        }

        /* QUEUE */
        $now = time();
        $end = $now + 15; // kurze Bauzeit für Bots

        $queue = serialize([
            [$elementId, $target, [], (float)$end, 'build']
        ]);

        Database::get()->update(
            'UPDATE %%PLANETS%%
             SET
                metal         = metal - :m,
                crystal       = crystal - :c,
                deuterium     = deuterium - :d,
                b_building    = :end,
                b_building_id = :queue
             WHERE id = :pid;',
            [
                ':m'     => (int)($cost[901] ?? 0),
                ':c'     => (int)($cost[902] ?? 0),
                ':d'     => (int)($cost[903] ?? 0),
                ':end'   => $end,
                ':queue' => $queue,
                ':pid'   => $planet['id'],
            ]
        );

        return true;
    }
}
