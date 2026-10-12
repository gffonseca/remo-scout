<?php

use Illuminate\Support\Facades\Schedule;

// Única estratégia de agendamento: scheduler Laravel no serviço worker (schedule:work). Horários em UTC (Belém = UTC−3).
Schedule::command('scout:probe-sources')->dailyAt('08:30')->withoutOverlapping();
Schedule::command('scout:sync-api-futebol --max=400')->dailyAt('09:00')->withoutOverlapping(120);   // 06:00 Belém
Schedule::command('scout:sync-api-football --max=400')->dailyAt('09:40')->withoutOverlapping(120); // 06:40 Belém
Schedule::command('scout:enrich transfermarkt --scope=priority --limit=40')->dailyAt('10:00')->withoutOverlapping(90);
Schedule::command('scout:sync-crests')->weeklyOn(0, '09:30')->withoutOverlapping();
Schedule::command('scout:sync-dados-futebol --max=40')->weeklyOn(1, '11:00')->withoutOverlapping(60); // elencos: segunda 08:00 Belém
Schedule::command('scout:sync-football-data')->weeklyOn(2, '12:00')->withoutOverlapping(30); // odds Série A: terça 09:00 Belém
