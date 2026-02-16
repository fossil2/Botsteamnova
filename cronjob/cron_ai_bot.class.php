<?php

class cron_ai_bot
{
    public function run(): void
    {
        require_once ROOT_PATH . 'includes/classes/BotBuildAI.php';
        require_once ROOT_PATH . 'includes/classes/BotResearchAI.php';
        require_once ROOT_PATH . 'includes/classes/BotDefenseAI.php';

        require_once ROOT_PATH . 'includes/vars.php';
        $cache = Cache::get();
        $cache->add('vars', 'VarsBuildCache');
        extract($cache->getData('vars'));

        $db  = Database::get();
        $now = TIMESTAMP;

        $prefix = DB_PREFIX;
        if (substr($prefix, -1) !== '_') {
            $prefix .= '_';
        }

        $usersTable   = $prefix . 'users';
        $planetsTable = $prefix . 'planets';

        $bots = $db->select(
            "SELECT id, bot_next_action
             FROM {$usersTable}
             WHERE is_bot = 1"
        );

        foreach ($bots as $bot)
        {
            if (!empty($bot['bot_next_action']) && (int)$bot['bot_next_action'] > $now) {
                continue;
            }

            usleep(random_int(80_000, 200_000));

            $userId = (int)$bot['id'];

            /* =========================
             * 👤 USER
             * ========================= */
            $USER = $db->selectSingle(
                "SELECT * FROM {$usersTable} WHERE id = :uid;",
                [':uid' => $userId]
            );

            if (!$USER) {
                continue;
            }

            /* =========================
             * 🟢 TEST: ONLINE-ZEIT SETZEN
             * ========================= */
            if (empty($USER['onlinetime']) || ($now - (int)$USER['onlinetime']) >= 3 * 86400) {
           $db->update(
            "UPDATE {$usersTable}
            SET onlinetime = :time
            WHERE id = :uid;",
           [
              ':time' => $now,
              ':uid'  => $userId,
          ]
          );
        }

            // Faktor wie beim Login
            $USER['factor'] = getFactors($USER, 'basic', $now);

            /* =========================
             * 🪐 HAUPTPLANET
             * ========================= */
             $PLANETS = $db->select(
             "SELECT *
             FROM {$planetsTable}
             WHERE id_owner = :uid
             AND planet_type = 1;",
             [':uid' => $userId]
             );

            if (empty($PLANETS)) {
            continue;
            }

            foreach ($PLANETS as $PLANET) {

            /* =========================
             * 🟢 Ressourcenproduktion
             * ========================= */
            $PLANET = $this->applyResourceProduction($USER, $PLANET);

            /* =========================
             * 🏗 Gebäude fertigstellen
             * ========================= */
            if (
                (int)$PLANET['b_building'] > 0 &&
                (int)$PLANET['b_building'] <= $now
            ) {
                $this->finishPlanetBuilding($PLANET, $resource, $planetsTable);
            }
            }
            /* =========================
             * 🤖 BOT LOGIK
             * ========================= */
            BotBuildAI::run($userId);
            BotResearchAI::run($userId);
            BotDefenseAI::run($userId);

            /* =========================
             * ⏱ COOLDOWN
             * ========================= */
            $db->update(
                "UPDATE {$usersTable}
                 SET bot_next_action = :next
                 WHERE id = :uid;",
                [
                    ':uid'  => $userId,
                    ':next' => $now + random_int(300, 900),
                ]
            );
        }
    }

    /* =========================
     * Ressourcenproduktion
     * ========================= */
    private function applyResourceProduction(array $USER, array $PLANET): array
    {
        require_once ROOT_PATH . 'includes/classes/class.PlanetRessUpdate.php';

        if (empty($USER['factor'])) {
            $USER['factor'] = getFactors($USER, 'basic', TIMESTAMP);
        }

        $eco = new ResourceUpdate();
        $eco->CalcResource($USER, $PLANET, true);

        return Database::get()->selectSingle(
            "SELECT * FROM ".DB_PREFIX."planets WHERE id = :pid;",
            [':pid' => $PLANET['id']]
        ) ?: $PLANET;
    }

    private function finishPlanetBuilding(array $PLANET, array $resource, string $planetsTable): void
    {
        $db = Database::get();

        $queue = @unserialize($PLANET['b_building_id'] ?? '');

        if (!is_array($queue) || empty($queue[0])) {
            $db->update(
                "UPDATE {$planetsTable}
                 SET b_building = 0, b_building_id = NULL
                 WHERE id = :pid;",
                [':pid' => $PLANET['id']]
            );
            return;
        }

        $job = $queue[0];
        $field = $resource[(int)$job[0]] ?? null;

        if (!$field) {
            return;
        }

        $db->update(
            "UPDATE {$planetsTable}
             SET {$field} = :lvl,
                 b_building = 0,
                 b_building_id = NULL
             WHERE id = :pid;",
            [
                ':lvl' => (int)$job[1],
                ':pid' => $PLANET['id'],
            ]
        );
    }
}
