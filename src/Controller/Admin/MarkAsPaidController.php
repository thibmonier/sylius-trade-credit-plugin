<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * "Mark as paid" on an open item: the core `sylius_payment` `complete` transition of the pending
 * payment (bank transfer received). It settles the reservation and, with the Invoicing Plugin,
 * dispatches OrderPaymentPaid like any other payment.
 */
final class MarkAsPaidController
{
    public function __construct(
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): RedirectResponse
    {
        $terms = $this->termsRepository->find($id);
        $order = $terms?->getOrder();
        if (null === $terms || !$order instanceof OrderInterface) {
            throw new NotFoundHttpException('Open item not found.');
        }

        $redirect = new RedirectResponse($this->urlGenerator->generate('tmonier_trade_credit_admin_open_item_index'));
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('tmonier_trade_credit_mark_as_paid' . $id, (string) $request->request->get('_csrf_token')))) {
            $this->flash($request, 'error', 'sylius.ui.invalid_csrf_token');

            return $redirect;
        }

        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW) ?? $order->getLastPayment(PaymentInterface::STATE_PROCESSING);
        if (!$terms->isReserved() || null === $payment || !$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
            $this->flash($request, 'error', 'tmonier_trade_credit.flash.cannot_mark_as_paid');

            return $redirect;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager->flush();
        $this->flash($request, 'success', 'tmonier_trade_credit.flash.marked_as_paid');

        return $redirect;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
