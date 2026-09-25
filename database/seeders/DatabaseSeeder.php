<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Stats\StatisticsRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Données d'initialisation : un Admin et les comptes des agents.
 *
 * Rejouable : un compte existant (même e-mail) n'est pas recréé. Les mots de
 * passe par défaut sont à changer dès la première connexion.
 */
class DatabaseSeeder extends Seeder
{
    public function run(StatisticsRecorder $recorder): void
    {
        $password = (string) env('AG_SEED_PASSWORD', 'ArticleGuard2026');

        $admin = User::query()->firstOrNew(['email' => 'admin@articleguard.test']);

        if (! $admin->exists) {
            $admin->fill(['name' => 'Admin', 'password' => $password]);
            $admin->forceFill(['role' => User::ROLE_ADMIN, 'is_active' => true])->save();
        }

        foreach ((array) config('articleguard.seed_names') as $name) {
            $email = Str::slug($name).'@articleguard.test';

            // Un compte du même nom existe déjà (créé à la main) : on le garde.
            if (User::query()->where('email', $email)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                continue;
            }

            $agent = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $agent->forceFill(['role' => User::ROLE_AGENT, 'is_active' => true])->save();

            $recorder->linkAgentHistory($agent);
        }

        $this->command?->info('Comptes prêts (mot de passe par défaut : '.$password.'). Changez-les après la première connexion.');
    }
}
