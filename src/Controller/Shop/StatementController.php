<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Controller\Shop;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;
use Twig\Environment;

/** "Trade credit" page of the customer account. */
final class StatementController
{
    public function __construct(
        private readonly StatementBuilder $statementBuilder,
        private readonly Security $security,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->security->getUser();
        $customer = $user instanceof ShopUserInterface ? $user->getCustomer() : null;
        if (!$customer instanceof CustomerInterface) {
            return new RedirectResponse($this->urlGenerator->generate('sylius_shop_login'));
        }

        return new Response($this->twig->render('@TmonierSyliusTradeCreditPlugin/shop/statement.html.twig', [
            'statement' => $this->statementBuilder->forCustomer($customer),
        ]));
    }
}
