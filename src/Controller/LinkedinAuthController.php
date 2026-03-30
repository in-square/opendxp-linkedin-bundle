<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Controller;

use InSquare\OpendxpLinkedinBundle\Service\LinkedinOAuthService;
use InSquare\OpendxpLinkedinBundle\Service\LinkedinTokenStorage;
use OpenDxp\Controller\FrontendController;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class LinkedinAuthController extends FrontendController
{
    #[Route('/admin/linkedin/connect', name: 'linkedin_connect', methods: ['GET'])]
    public function connect(SessionInterface $session, LinkedinOAuthService $oauthService, LoggerInterface $logger): Response
    {
        $session->start();
        $state = bin2hex(random_bytes(16));
        $session->set('linkedin_oauth_state', $state);
        $logger->info('LinkedIn OAuth connect.', [
            'session_id' => $session->getId(),
            'session_name' => $session->getName(),
            'state' => $state,
        ]);

        return new RedirectResponse($oauthService->getAuthorizationUrl($state));
    }

    #[Route('/admin/linkedin/callback', name: 'linkedin_callback', methods: ['GET'])]
    public function callback(
        Request $request,
        SessionInterface $session,
        LinkedinOAuthService $oauthService,
        LinkedinTokenStorage $tokenStorage,
        LoggerInterface $logger
    ): Response {
        $session->start();
        $error = $request->query->get('error');
        if ($error) {
            $logger->error('LinkedIn OAuth error: ' . $error);
            return new Response('LinkedIn error: ' . $error, Response::HTTP_BAD_REQUEST);
        }

        $state = (string) $request->query->get('state', '');
        $expectedState = (string) $session->get('linkedin_oauth_state');

        $logger->info('LinkedIn OAuth callback.', [
            'session_id' => $session->getId(),
            'session_name' => $session->getName(),
            'state' => $state,
            'expected_state' => $expectedState,
        ]);

        if ($state === '' || $expectedState === '' || $state !== $expectedState) {
            $logger->error('LinkedIn OAuth state mismatch.');
            return new Response('Invalid state', Response::HTTP_BAD_REQUEST);
        }

        $code = (string) $request->query->get('code', '');
        if ($code === '') {
            $logger->error('LinkedIn OAuth code missing.');
            return new Response('Missing code', Response::HTTP_BAD_REQUEST);
        }

        try {
            $token = $oauthService->exchangeCodeForToken($code);
            $tokenStorage->saveToken($token);
            $session->remove('linkedin_oauth_state');
        } catch (\Throwable $exception) {
            $logger->error('LinkedIn OAuth token exchange failed: ' . $exception->getMessage());
            return new Response('Token exchange failed', Response::HTTP_BAD_REQUEST);
        }

        return new Response('Połączono. Możesz zamknąć okno.');
    }
}
