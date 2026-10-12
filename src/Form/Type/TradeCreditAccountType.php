<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Form\Type;

use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;

final class TradeCreditAccountType extends AbstractResourceType
{
    /**
     * @param RepositoryInterface<CurrencyInterface> $currencyRepository
     * @param list<string> $validationGroups
     */
    public function __construct(
        string $dataClass,
        private readonly RepositoryInterface $currencyRepository,
        array $validationGroups = [],
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencies = [];
        foreach ($this->currencyRepository->findAll() as $currency) {
            $currencies[(string) $currency->getCode()] = (string) $currency->getCode();
        }

        $builder
            ->add('enabled', CheckboxType::class, ['label' => 'sylius.ui.enabled', 'required' => false])
            ->add('currencyCode', ChoiceType::class, [
                'label' => 'sylius.ui.currency',
                'choices' => $currencies,
                'constraints' => [new NotBlank(groups: ['sylius'])],
            ])
            ->add('creditHold', CheckboxType::class, ['label' => 'tmonier_trade_credit.form.credit_hold', 'help' => 'tmonier_trade_credit.form.credit_hold_help', 'required' => false])
            ->add('allowExceed', CheckboxType::class, ['label' => 'tmonier_trade_credit.form.allow_exceed', 'help' => 'tmonier_trade_credit.form.allow_exceed_help', 'required' => false])
            ->add('blockWhenOverdue', CheckboxType::class, ['label' => 'tmonier_trade_credit.form.block_when_overdue', 'help' => 'tmonier_trade_credit.form.block_when_overdue_help', 'required' => false])
            ->add('netDays', IntegerType::class, [
                'label' => 'tmonier_trade_credit.form.net_days',
                'help' => 'tmonier_trade_credit.form.account_net_days_help',
                'required' => false,
                'constraints' => [new GreaterThanOrEqual(0, groups: ['sylius'])],
            ])
            ->add('endOfMonthMode', EnumType::class, [
                'label' => 'tmonier_trade_credit.form.end_of_month_mode',
                'class' => EndOfMonthMode::class,
                'required' => false,
                'placeholder' => 'tmonier_trade_credit.form.policy_default',
                'choice_label' => static fn (EndOfMonthMode $mode): string => 'tmonier_trade_credit.end_of_month_mode.' . $mode->value,
            ])
            ->add('note', TextareaType::class, ['label' => 'tmonier_trade_credit.form.note', 'required' => false])
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $account = $event->getData();
                $event->getForm()->add('creditLimit', MoneyType::class, [
                    'label' => 'tmonier_trade_credit.form.credit_limit',
                    'currency' => $account instanceof TradeCreditAccountInterface ? ($account->getCurrencyCode() ?? 'USD') : 'USD',
                    'constraints' => [new NotBlank(groups: ['sylius']), new GreaterThanOrEqual(0, groups: ['sylius'])],
                ]);
            })
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_trade_credit_account';
    }
}
