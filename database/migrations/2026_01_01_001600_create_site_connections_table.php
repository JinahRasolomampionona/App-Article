<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connexions WordPress par compte.
 *
 * Un site WordPress n'existe qu'une fois (ses articles, verrous, audits et
 * statistiques sont partagés), mais chaque compte — Admin ou Agent — le
 * connecte avec ses propres identifiants. Les identifiants et l'état de la
 * connexion quittent donc `wordpress_sites` pour `site_connections`.
 *
 * Les sites existants sont repris tels quels : leur connexion devient celle du
 * compte qui les avait connectés.
 */
return new class extends Migration
{
    /** Colonnes déplacées de `wordpress_sites` vers `site_connections`. */
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
        Schema::create('site_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('wp_username')->nullable();
            // Chiffré via le cast "encrypted" du modèle : jamais stocké en clair.
            $table->text('application_password')->nullable();
            $table->boolean('wp_can_edit')->nullable();
            $table->string('wp_role', 64)->nullable();
            $table->string('connection_status', 32)->default('unknown');
            $table->string('connection_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['wordpress_site_id', 'user_id']);
            $table->index('user_id');
        });

        $present = array_values(array_filter(
            $this->columns,
            fn (string $column) => Schema::hasColumn('wordpress_sites', $column),
        ));

        DB::table('wordpress_sites')->orderBy('id')->each(function ($site) use ($present) {
            $row = [
                'wordpress_site_id' => $site->id,
                'user_id' => $site->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ($present as $column) {
                // La valeur chiffrée est recopiée telle quelle : même clé, même cast.
                $row[$column] = $site->{$column};
            }

            DB::table('site_connections')->insert($row);
        });

        if ($present !== []) {
            Schema::table('wordpress_sites', function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }

    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->string('wp_username')->nullable();
            $table->text('application_password')->nullable();
            $table->boolean('wp_can_edit')->nullable();
            $table->string('wp_role', 64)->nullable();
            $table->string('connection_status', 32)->default('unknown');
            $table->string('connection_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
        });

        // Chaque site reprend la connexion de son créateur.
        DB::table('site_connections as c')
            ->join('wordpress_sites as s', function ($join) {
                $join->on('s.id', '=', 'c.wordpress_site_id')->on('s.user_id', '=', 'c.user_id');
            })
            ->select('c.*')
            ->orderBy('c.id')
            ->each(function ($connection) {
                DB::table('wordpress_sites')->where('id', $connection->wordpress_site_id)->update(
                    collect($this->columns)->mapWithKeys(fn ($column) => [$column => $connection->{$column}])->all()
                );
            });

        Schema::dropIfExists('site_connections');
    }
};
