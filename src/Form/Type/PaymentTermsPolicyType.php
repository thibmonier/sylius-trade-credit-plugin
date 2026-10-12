<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Form\Type;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;

final class PaymentTermsPolicyType extends AbstractResourceType
{
    /** @param list<string> $validationGroups */
    public function __construct(
        string $dataClass,
        private readonly string $paymentMethodClass,
        array $validationGroups = [],
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('paymentMethod', EntityType::class, [
                'label' => 'sylius.ui.payment_method',
                'help' => 'tmonier_trade_credit.form.payment_method_help',
                'class' => $this->paymentMethodClass,
                // Payment on account is an Offline method: capture leaves the order awaiting payment.
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('m')
                    ->innerJoin('m.gatewayConfig', 'g')
                    ->andWhere('g.factoryName = :offline')
                    ->setParameter('offline', 'offline')
                    ->orderBy('m.code', 'ASC'),
                'choice_label' => static fn (PaymentMethodInterface $method): string => sprintf('%s (%s)', (string) $method->getName(), (string) $method->getCode()),
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('netDays', IntegerType::class, [
                'label' => 'tmonier_trade_credit.form.net_days',
                'constraints' => [new NotBlank(groups: ['sylius']), new GreaterThanOrEqual(0, groups: ['sylius'])],
            ])
            ->add('endOfMonthMode', EnumType::class, [
                'label' => 'tmonier_trade_credit.form.end_of_month_mode',
                'class' => EndOfMonthMode::class,
                'choice_label' => static fn (EndOfMonthMode $mode): string => 'tmonier_trade_credit.end_of_month_mode.' . $mode->value,
            ])
            ->add('enabled', CheckboxType::class, ['label' => 'sylius.ui.enabled', 'required' => false])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_trade_credit_payment_terms_policy';
    }
}
