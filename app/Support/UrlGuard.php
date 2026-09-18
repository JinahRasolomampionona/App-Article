<?php

namespace App\Support;

/**
 * Garde-fou SSRF.
 *
 * Le domaine WordPress est une saisie utilisateur : avant tout appel réseau on
 * vérifie le schéma, le port, puis on résout le nom pour s'assurer qu'aucune
 * des adresses obtenues n'appartient à une plage privée ou réservée.
 *
 * Remarque : la résolution DNS est refaite à chaque vérification. Cela ne rend
 * pas un rebinding DNS strictement impossible, mais ferme le cas très
 * majoritaire (saisie directe de `localhost`, `127.0.0.1`, `10.x`, `169.254.x`,
 * `.internal`, etc.).
 */
class UrlGuard
{
    /**
     * Normalise une saisie utilisateur en URL de base exploitable.
     *
     * « bijouteries.top », « https://bijouteries.top/ » et
     * « https://bijouteries.top/blog/ » deviennent respectivement
     * « https://bijouteries.top », « https://bijouteries.top » et
     * « https://bijouteries.top/blog ».
     */
    public function normalize(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw UnsafeUrlException::malformed();
        }

        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw UnsafeUrlException::malformed();
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, $this->allowedSchemes(), true)) {
            throw UnsafeUrlException::scheme();
        }

        $host = strtolower($parts['host']);

        if (! $this->isValidHost($host)) {
            throw UnsafeUrlException::malformed();
        }

        $url = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $path = rtrim($parts['path'] ?? '', '/');

        // Les endpoints REST éventuellement collés par l'utilisateur sont retirés.
        $path = preg_replace('#/wp-json(/.*)?$#i', '', $path) ?? '';

        return $url.$path;
    }

    /**
     * Vérifie qu'une URL peut être contactée, ou lève une UnsafeUrlException.
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw UnsafeUrlException::malformed();
        }

        if (! in_array(strtolower($parts['scheme']), $this->allowedSchemes(), true)) {
            throw UnsafeUrlException::scheme();
        }

        $host = strtolower($parts['host']);
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        if (! in_array($port, $this->allowedPorts(), true)) {
            throw UnsafeUrlException::port($port);
        }

        if ($this->isAllowlisted($host)) {
            return;
        }

        if (config('articleguard.ssrf.allow_private')) {
            return;
        }

        foreach ($this->resolve($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw UnsafeUrlException::internal($host);
            }
        }
    }

    public function isSafe(string $url): bool
    {
        try {
            $this->assertSafe($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * Résout un hôte en liste d'adresses IP.
     *
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        // Une IP littérale n'a pas besoin de résolution.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return [trim($host, '[]')];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        $ips = array_values(array_unique($ips));

        if ($ips === []) {
            throw UnsafeUrlException::unresolvable($host);
        }

        return $ips;
    }

    protected function isPublicIp(string $ip): bool
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            return false;
        }

        // Filets de sécurité supplémentaires que les flags PHP ne couvrent pas
        // toujours selon la version : IPv4 mappée en IPv6 et NAT64.
        $lower = strtolower($ip);

        if (str_starts_with($lower, '::ffff:') || str_starts_with($lower, '64:ff9b::')) {
            return false;
        }

        return true;
    }

    protected function isAllowlisted(string $host): bool
    {
        foreach ((array) config('articleguard.ssrf.allowlist', []) as $allowed) {
            if (strtolower(trim((string) $allowed)) === $host) {
                return true;
            }
        }

        return false;
    }

    protected function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
    }

    /** @return array<int, string> */
    protected function allowedSchemes(): array
    {
        return (array) config('articleguard.ssrf.allowed_schemes', ['http', 'https']);
    }

    /** @return array<int, int> */
    protected function allowedPorts(): array
    {
        return array_map('intval', (array) config('articleguard.ssrf.allowed_ports', [80, 443]));
    }
}
