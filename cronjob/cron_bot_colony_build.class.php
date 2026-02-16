<?php
declare(strict_types=1);

class cron_bot_colony_build
{
    public function run(): void
    {
        // 🔑 AI-Klasse laden (WICHTIG!)
        require_once ROOT_PATH . 'includes/classes/BotColonyBuildAI.php';

        $db = Database::get();

        // alle Bots holen
        $bots = $db->select(
            'SELECT id FROM %%USERS%% WHERE is_bot = 1;'
        );

        foreach ($bots as $bot) {
            BotColonyBuildAI::run((int)$bot['id']);
        }
    }
}