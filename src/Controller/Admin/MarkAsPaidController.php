<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusTradeCreditPlugin\Payment\OpenItemSettler;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/** "Mark as paid" on an open item (bank transfer received), see `OpenItemSettler`. */
final readonly class MarkAsPaidController
{
    public function __construct(
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private OpenItemSettler $settler,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
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

        if (!$this->settler->settle($terms)) {
            $this->flash($request, 'error', 'tmonier_trade_credit.flash.cannot_mark_as_paid');

            return $redirect;
        }

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
