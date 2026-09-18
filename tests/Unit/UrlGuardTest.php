<?php

namespace Tests\Unit;

use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    protected UrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new UrlGuard;
    }

    public function test_une_saisie_sans_protocole_est_normalisee_en_https(): void
    {
        $this->assertSame('https://bijouteries.top', $this->guard->normalize('bijouteries.top'));
    }

    public function test_le_slash_final_est_retire(): void
    {
        $this->assertSame('https://bijouteries.top', $this->guard->normalize('https://bijouteries.top/'));
    }

    public function test_un_sous_repertoire_est_conserve(): void
    {
        $this->assertSame('https://exemple.com/blog', $this->guard->normalize('https://exemple.com/blog/'));
    }

    public function test_l_endpoint_rest_colle_par_erreur_est_retire(): void
    {
        $this->assertSame(
            'https://exemple.com',
            $this->guard->normalize('https://exemple.com/wp-json/wp/v2/posts')
        );
    }

    public function test_un_protocole_non_http_est_refuse(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->guard->normalize('ftp://exemple.com');
    }

    #[DataProvider('internalHosts')]
    public function test_les_destinations_internes_sont_bloquees(string $url): void
    {
        config(['articleguard.ssrf.allow_private' => false, 'articleguard.ssrf.allowlist' => []]);

        $this->expectException(UnsafeUrlException::class);
        $this->guard->assertSafe($url);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function internalHosts(): array
    {
        return [
            'loopback' => ['http://127.0.0.1'],
            'reseau prive 10/8' => ['http://10.0.0.5'],
            'reseau prive 192.168' => ['http://192.168.1.10'],
            'reseau prive 172.16' => ['http://172.16.0.1'],
            'lien local (metadata cloud)' => ['http://169.254.169.254'],
            'loopback IPv6' => ['http://[::1]'],
            'IPv4 mappee en IPv6' => ['http://[::ffff:127.0.0.1]'],
        ];
    }

    public function test_un_port_non_autorise_est_refuse(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->guard->assertSafe('http://exemple.com:22');
    }

    public function test_un_hote_explicitement_autorise_passe(): void
    {
        config(['articleguard.ssrf.allowlist' => ['localhost']]);

        $this->assertTrue($this->guard->isSafe('http://localhost:8080'));
    }

    public function test_le_mode_developpement_leve_le_blocage(): void
    {
        config(['articleguard.ssrf.allow_private' => true, 'articleguard.ssrf.allowlist' => []]);

        $this->assertTrue($this->guard->isSafe('http://127.0.0.1:8080'));
    }
}
