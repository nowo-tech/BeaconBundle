<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\PasswordToggleBundle\Form\Type\PasswordType as TogglePasswordType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * REQ-DEMO-015: username / password form used by the demo firewall, a FormKit form (profile `demo`) with the
 * PasswordToggleBundle widget inside it.
 *
 * @extends AbstractType<array{_username?: string, _password?: string}>
 */
#[FormKitConfig('demo')]
final class LoginFormType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, '_username', [
            'label'              => 'Username',
            'translation_domain' => false,
            'placeholder'        => false,
            'help'               => false,
            'attr'               => ['autocomplete' => 'username', 'placeholder' => 'debugger'],
        ]);
        $this->addWithDefaults($builder, '_password', TogglePasswordType::class, [
            'label'              => 'Password',
            'translation_domain' => false,
            'placeholder'        => false,
            'help'               => 'Password toggle powered by PasswordToggleBundle.',
            'attr'               => ['autocomplete' => 'current-password'],
        ]);
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label'              => 'Sign in',
            'translation_domain' => false,
            'attr'               => ['class' => 'nowo-ui-btn nowo-ui-btn--primary'],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'action'          => '/login',
            'method'          => 'POST',
            // form_login (enable_csrf) reads _csrf_token with the "authenticate" token id (REQ-TWIG-005: no raw input).
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id'   => 'authenticate',
        ]);
    }

    /**
     * Empty prefix so field names match Symfony Security expectations.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
