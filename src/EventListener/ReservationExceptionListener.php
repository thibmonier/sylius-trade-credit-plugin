<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;

/**
 * Maps a refused reservation (limit reached between the payment step and the completion,
 * credit hold set meanwhile...) to a 422 in the API and to a redirect with a flash message in
 * the shop and the admin, instead of a server error. Messenger wraps handler exceptions: the
 * chain of previous exceptions is searched.
 */
final readonly class ReservationExceptionListener
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $this->find($event->getThrowable());
        if (!$exception instanceof \Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException) {
            return;
        }

        $request = $event->getRequest();
        $message = $this->translator->trans($exception->getTranslationKey(), [], 'validators');

        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $event->setResponse(new JsonResponse([
                '@type' => 'ConstraintViolationList',
                'title' => 'An error occurred',
                'detail' => $message,
                'description' => $message,
                'violations' => [['propertyPath' => '', 'message' => $message, 'code' => 'tmonier_trade_credit_reservation']],
            ], 422, ['Content-Type' => 'application/problem+json']));

            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $exception->getTranslationKey());
        }

        $event->setResponse(new RedirectResponse($this->target($request, $exception)));
    }

    private function target(Request $request, TradeCreditReservationException $exception): string
    {
        $route = $request->attributes->get('_route');
        $locale = $request->attributes->get('_locale');
        $localeParameters = is_string($locale) ? ['_locale' => $locale] : [];

        if ('sylius_shop_order_show' === $route && null !== $exception->getOrderTokenValue()) {
            return $this->urlGenerator->generate('sylius_shop_order_show', ['tokenValue' => $exception->getOrderTokenValue()] + $localeParameters);
        }
        if (is_string($route) && str_starts_with($route, 'sylius_shop_')) {
            return $this->urlGenerator->generate('sylius_shop_checkout_complete', $localeParameters);
        }

        $referer = $request->headers->get('referer');

        return is_string($referer) && '' !== $referer ? $referer : $request->getUri();
    }

    private function find(\Throwable $throwable): ?TradeCreditReservationException
    {
        for ($current = $throwable; $current instanceof \Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TradeCreditReservationException) {
                return $current;
            }
        }

        return null;
    }
}
