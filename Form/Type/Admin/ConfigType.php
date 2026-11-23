<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Form\Type\Admin;

use Plugin\BlokbayPayment\Service\BlokbayApi;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Keys are never shown again: an empty secret field keeps the saved value.
 */
class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('secret_key', PasswordType::class, [
                'required' => false,
                'mapped' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'sk_live_...'],
                'constraints' => [new Assert\Callback(function ($v, $ctx) {
                    if ((string) $v !== '' && !BlokbayApi::isSecretKey(trim((string) $v))) {
                        $ctx->addViolation('blokbay_payment.admin.err_secret_key');
                    }
                })],
            ])
            ->add('public_key', TextType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'pk_live_...'],
                'constraints' => [new Assert\Callback(function ($v, $ctx) {
                    if ((string) $v !== '' && !BlokbayApi::isPublicKey(trim((string) $v))) {
                        $ctx->addViolation('blokbay_payment.admin.err_public_key');
                    }
                })],
            ])
            ->add('webhook_secret', PasswordType::class, [
                'required' => false,
                'mapped' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'whsec_...'],
                'constraints' => [new Assert\Callback(function ($v, $ctx) {
                    if ((string) $v !== '' && !BlokbayApi::isWebhookSecret(trim((string) $v))) {
                        $ctx->addViolation('blokbay_payment.admin.err_webhook_secret');
                    }
                })],
            ])
            ->add('api_base', TextType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['placeholder' => BlokbayApi::DEFAULT_BASE],
                'constraints' => [new Assert\Callback(function ($v, $ctx) {
                    if (!BlokbayApi::isApiBase((string) $v)) {
                        $ctx->addViolation('blokbay_payment.admin.err_api_base');
                    }
                })],
            ]);
    }
}
