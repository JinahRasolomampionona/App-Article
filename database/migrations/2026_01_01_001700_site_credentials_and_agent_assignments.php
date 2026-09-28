<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sites gérés par l'Admin, assignés aux agents.
 *
 * - `site_credentials` : identifiants WordPress (Application Password
 *   chiffrée) d'un site — une ligne par site, saisie par l'Admin ;
 * - `site_agent_assignments` : sites assignés à chaque agent, avec leur état
 *   (« En cours » à l'assignation, « Terminé » quand l'Admin le clôt).
 *
 * Remplace `site_connections` (une connexion par compte). Pour chaque site,
 * la connexion du créateur devient les identifiants du site ; les agents qui
 * l'avaient connecté y sont assignés.
 */
return new class extends Migration
{
    protected array $columns = [
        'wp_username',
        'application_password',
        'wp_can_edit',
        'wp_role',
        'connection_status',
        'connection_message',
        'last_checked_at',
    ];

    public function up(): void
    {
        Schema::create('site_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('wp_username')->nullable();
            // Chiffré via le cast "encrypted" du modèle : jamais stocké en clair.
            $table->text('application_password')->nullable();
            $table->boolean('wp_can_edit')->nullable();
            $table->string('wp_role', 64)->nullable();
            $table->string('connection_status', 32)->default('unknown');
            $table->string('connection_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_agent_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // in_progress · done
            $table->string('status', 16)->default('in_progress');
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['wordpress_site_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        if (Schema::hasTable('site_connections')) {
            $this->migrateConnections();
            Schema::drop('site_connections');
        }
    }

    protected function migrateConnections(): void
    {
        $agents = DB::table('users')->where('role', 'agent')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (DB::table('wordpress_sites')->orderBy('id')->get() as $site) {
            $connections = DB::table('site_connections')->where('wordpress_site_id', $site->id)->get();

            $hasCredentials = fn ($c) => filled($c->wp_username) && filled($c->application_password);

            $chosen = $connections->first(fn ($c) => (int) $c->user_id === (int) $site->user_id && $hasCredentials($c))
                ?? $connections->first($hasCredentials)
                ?? $connections->first(fn ($c) => (int) $c->user_id === (int) $site->user_id)
                ?? $connections->first();

            $row = ['wordpress_site_id' => $site->id, 'created_at' => now(), 'updated_at' => now()];

            foreach ($this->columns as $column) {
                $row[$column] = $chosen?->{$column};
            }

            $row['connection_status'] ??= 'unknown';

            DB::table('site_credentials')->insert($row);

            foreach ($connections as $connection) {
                if (in_array((int) $connection->user_id, $agents, true)) {
                    DB::table('site_agent_assignments')->insert([
                        'wordpress_site_id' => $site->id,
                        'user_id' => $connection->user_id,
                        'status' => 'in_progress',
                        'assigned_at' => $connection->created_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::create('site_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('wp_username')->nullable();
            $table->text('application_password')->nullable();
            $table->boolean('wp_can_edit')->nullable();
            $table->string('wp_role', 64)->nullable();
            $table->string('connection_status', 32)->default('unknown');
            $table->string('connection_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['wordpress_site_id', 'user_id']);
        });

        foreach (DB::table('site_credentials as c')->join('wordpress_sites as s', 's.id', '=', 'c.wordpress_site_id')->select('c.*', 's.user_id')->get() as $credential) {
            $row = ['wordpress_site_id' => $credential->wordpress_site_id, 'user_id' => $credential->user_id, 'created_at' => now(), 'updated_at' => now()];

            foreach ($this->columns as $column) {
                $row[$column] = $credential->{$column};
            }

            DB::table('site_connections')->insert($row);
        }

        Schema::dropIfExists('site_agent_assignments');
        Schema::dropIfExists('site_credentials');
    }
};
