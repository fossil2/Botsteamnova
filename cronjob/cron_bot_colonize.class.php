<?php

class cron_bot_colonize
{
    public function run(): void
    {
        require_once ROOT_PATH . 'includes/classes/BotColonizeAI.php';

        $db  = Database::get();
        $now = TIMESTAMP;

        $prefix = DB_PREFIX;
        if (substr($prefix, -1) !== '_') {
            $prefix .= '_';
        }

        $usersTable = $prefix . 'users';

        $bots = $db->select(
            "SELECT id, bot_next_colonize
             FROM {$usersTable}
             WHERE is_bot = 1"
        );

        foreach ($bots as $bot) {

            if (!empty($bot['bot_next_colonize']) && (int)$bot['bot_next_colonize'] > $now) {
                continue;
            }

            usleep(random_int(150_000, 350_000));

            BotColonizeAI::run((int)$bot['id']);
        }
    }
}
