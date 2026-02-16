/*
 * Projekt: https://github.com/fossil2/BotsteamNova.git
 * Autor: Fossil
 * Datum: 2026-02-16
 */


<?php

class cron_bot_spy
{
    public function run(): void
    {
        require_once ROOT_PATH . 'includes/classes/BotSpyAI.php';

        $db  = Database::get();
        $now = TIMESTAMP;

        $prefix = DB_PREFIX;
        if (substr($prefix, -1) !== '_') {
            $prefix .= '_';
        }

        $usersTable = $prefix . 'users';

        $bots = $db->select(
            "SELECT id, bot_next_spy
             FROM {$usersTable}
             WHERE is_bot = 1"
        );

        foreach ($bots as $bot) {

            if (!empty($bot['bot_next_spy']) && (int)$bot['bot_next_spy'] > $now) {
                continue;
            }

            usleep(random_int(80_000, 200_000));

            BotSpyAI::run((int)$bot['id']);
        }
    }
}
