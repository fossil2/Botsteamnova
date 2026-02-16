/*
 * Projekt: https://github.com/fossil2/BotsteamNova.git
 * Autor: Fossil
 * Datum: 2026-02-16
 */


<?php

class cron_fleet_engine
{
    public function run(): void
    {
        require_once ROOT_PATH . 'includes/classes/class.FlyingFleetHandler.php';

        $fleetHandler = new FlyingFleetHandler();
        $fleetHandler->run();
    }
}
