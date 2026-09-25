<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Security;

use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
use Neos\OpenApi\Http\Authenticated;
use Neos\OpenApi\Http\AuthenticationResult;
use Neos\OpenApi\Http\Unauthenticated;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Neos\OpenApi\Support\SecuritySchemeApiKeyLocation;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates requests through the session of the Neos backend, i.e. the API can be used by the Neos UI (or the
 * Swagger UI) of a logged-in editor without further credentials
 *
 * There is no CSRF protection, which is fine as long as the API is read-only
 */
final readonly class BackendSessionAuthContextProvider implements AuthContextProviderWithSchemes
{
    /**
     * The name of the security scheme, as the operations refer to it
     */
    public const string SCHEME = 'neosBackendSession';

    private const string REQUIRED_ROLE = 'Neos.Neos:AbstractEditor';

    /**
     * @param array<string, bool> $authenticationProviders
     */
    public function __construct(
        private AuthenticationManagerInterface $authenticationManager,
        private SecurityContext $securityContext,
        private string $sessionCookieName,
        private array $authenticationProviders,
    ) {
    }

    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        return SecuritySchemeOrReferenceObjectMap::create()->with(
            self::SCHEME,
            SecuritySchemeObject::apiKey(
                $this->sessionCookieName,
                SecuritySchemeApiKeyLocation::cookie,
                'The session of a user logged in to the Neos backend',
            ),
        );
    }

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): AuthenticationResult
    {
        // authenticates the tokens Flow collected (or restored from the session) – without throwing if there are none
        $this->authenticationManager->isAuthenticated();
        foreach ($this->securityContext->getAuthenticationTokens() as $token) {
            if (($this->authenticationProviders[$token->getAuthenticationProviderName()] ?? false) !== true) {
                continue;
            }
            $account = $token->getAccount();
            if ($token->isAuthenticated() && $account !== null && $this->securityContext->hasRole(self::REQUIRED_ROLE)) {
                return Authenticated::as(new BackendUser($account->getAccountIdentifier()));
            }
        }
        return Unauthenticated::create();
    }
}
