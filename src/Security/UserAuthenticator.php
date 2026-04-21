<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class UserAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->getPayload()->getString('email');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $userIdentifier) {
                $user = $this->userRepository->findOneBy(['email' => $userIdentifier]);
                if (!$user instanceof User) {
                    throw new CustomUserMessageAuthenticationException('Identifiants invalides.');
                }

                if (UserStatus::PENDING === $user->getStatus()) {
                    throw new CustomUserMessageAuthenticationException('Votre compte est en attente de validation.');
                }

                if (in_array($user->getStatus(), [UserStatus::SUSPENDED, UserStatus::BANNED, UserStatus::DELETED], true)) {
                    throw new CustomUserMessageAuthenticationException('Votre compte ne peut pas se connecter.');
                }

                return $user;
            }),
            new PasswordCredentials($request->getPayload()->getString('password')),
            [
                new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        $roles = $token->getRoleNames();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return new RedirectResponse($this->urlGenerator->generate('app_admin_dashboard'));
        }

        if (in_array('ROLE_RECRUITER', $roles, true)) {
            return new RedirectResponse($this->urlGenerator->generate('app_recruiter_home'));
        }

        if (in_array('ROLE_APPLICANT', $roles, true)) {
            return new RedirectResponse($this->urlGenerator->generate('app_applicant_home'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_error_403'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
