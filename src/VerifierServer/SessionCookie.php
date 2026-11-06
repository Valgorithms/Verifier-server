<?php declare(strict_types=1);

namespace VerifierServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Browser sessions for the OAuth2 web endpoints.
 *
 * A random id travels in an HttpOnly cookie shared by every endpoint on the
 * server, so the Discord and SS14 logins of one browser land under the same
 * key and can be correlated, while two players behind one NAT never share a
 * session. Entries expire after TTL seconds of inactivity.
 */
final class SessionCookie
{
    public const NAME = 'verifier_session';
    public const TTL = 86400;
    private const ID_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * Returns the session id for this request and whether it was just minted.
     *
     * @return array{0: string, 1: bool}
     */
    public static function resolve(ServerRequestInterface $request): array
    {
        $id = $request->getCookieParams()[self::NAME] ?? null;
        if (is_string($id) && preg_match(self::ID_PATTERN, $id) === 1) {
            return [$id, false];
        }
        return [bin2hex(random_bytes(16)), true];
    }

    /** Drops every entry of `$sessions` that has not been touched within TTL. */
    public static function prune(array &$sessions, ?int $now = null): void
    {
        $now = $now ?? time();
        foreach ($sessions as $id => $session) {
            if (!is_array($session) || ($session['touched'] ?? 0) < $now - self::TTL) {
                unset($sessions[$id]);
            }
        }
    }

    public static function attach(ResponseInterface $response, string $id): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax', self::NAME, $id, self::TTL)
        );
    }
}
